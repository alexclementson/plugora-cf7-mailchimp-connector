/* Plugora CF7 → Mailchimp — admin JS */
(function(){
  if (!window.wp || !wp.apiFetch || !window.PlugoraCF7MC) return;
  var cfg = window.PlugoraCF7MC;
  if (wp.apiFetch.createNonceMiddleware) wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(cfg.nonce));
  if (wp.apiFetch.createRootURLMiddleware) wp.apiFetch.use(wp.apiFetch.createRootURLMiddleware(cfg.restRoot));

  function api(path, opts) { return wp.apiFetch(Object.assign({ path: cfg.ns + path }, opts || {})); }
  function safe(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function el(tag, attrs, kids){
    var n = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      if (k === 'class') n.className = attrs[k];
      else if (k === 'html') n.innerHTML = attrs[k];
      else if (k === 'text') n.textContent = attrs[k];
      else n.setAttribute(k, attrs[k]);
    }
    (kids || []).forEach(function(c){ if (c) n.appendChild(c); });
    return n;
  }
  function parseJSON(s){ try { return s ? JSON.parse(s) : null; } catch(e){ return null; } }
  function fmtTime(d){
    try {
      return d.toLocaleTimeString(undefined, { hour:'2-digit', minute:'2-digit' });
    } catch(e){ return ''; }
  }

  // ---- Settings page: Check API key ----
  function bindKeyCheck() {
    var btn = document.querySelector('.plugora-cf7mc-check-key');
    var input = document.getElementById('plugora_cf7mc_api_key');
    var status = document.querySelector('.plugora-cf7mc-key-status');
    if (!btn || !input || !status) return;
    btn.addEventListener('click', function(){
      var key = input.value.trim();
      if (!key) { status.textContent = cfg.i18n.invalid_key; status.className = 'plugora-cf7mc-key-status err'; return; }
      status.textContent = cfg.i18n.checking; status.className = 'plugora-cf7mc-key-status';
      api('/validate', { method: 'POST', data: { api_key: key } }).then(function(r){
        if (r.ok) {
          status.textContent = '✓ ' + cfg.i18n.connected_to + ' ' + (r.account_name || r.datacenter);
          status.className = 'plugora-cf7mc-key-status ok';
        } else {
          status.textContent = '✗ ' + (r.error || cfg.i18n.invalid_key);
          status.className = 'plugora-cf7mc-key-status err';
        }
      }).catch(function(){ status.textContent = cfg.i18n.invalid_key; status.className = 'plugora-cf7mc-key-status err'; });
    });
  }

  // ---- Heuristics: auto-suggest CF7 tags for Mailchimp merge fields ----
  // Score per (mailTag, mergeField) pair; higher = better match.
  function scoreMatch(cf7Tag, mergeTag, mergeName, mergeType) {
    var t = cf7Tag.toLowerCase();
    var mt = (mergeTag || '').toLowerCase();
    var mn = (mergeName || '').toLowerCase();
    var hits = function(needles){ return needles.some(function(n){ return t.indexOf(n) !== -1; }); };
    if (mt === 'email' || mergeType === 'email') {
      if (hits(['email','e-mail'])) return 100;
    }
    if (mt === 'fname') {
      if (hits(['first-name','firstname','fname','first_name'])) return 95;
      if (hits(['name']) && !hits(['last','sur','full'])) return 60;
    }
    if (mt === 'lname') {
      if (hits(['last-name','lastname','lname','last_name','surname'])) return 95;
    }
    if (mt === 'phone' || mergeType === 'phone') {
      if (hits(['phone','tel','mobile','cell'])) return 90;
    }
    if (mt === 'address' || mergeType === 'address') {
      if (hits(['address','street'])) return 80;
    }
    if (mt === 'birthday' || mergeType === 'birthday') {
      if (hits(['birth','dob'])) return 80;
    }
    if (mt === 'company' || hits(['company','organisation','organization','business']) && mn.indexOf('company') !== -1) return 70;
    // Fallback: same word in mergeTag/Name appears in cf7 tag
    if (mt && t.indexOf(mt) !== -1) return 50;
    if (mn && t.indexOf(mn.replace(/\s+/g,'-')) !== -1) return 40;
    return 0;
  }
  function bestMatch(mailTags, mergeTag, mergeName, mergeType) {
    var best = { score: 0, tag: '' };
    mailTags.forEach(function(t){
      var s = scoreMatch(t, mergeTag, mergeName, mergeType);
      if (s > best.score) best = { score: s, tag: t };
    });
    return best.score >= 50 ? best.tag : '';
  }

  // ---- CF7 form editor: audience + field mapping ----
  function bindCF7Panel() {
    var panel = document.querySelector('.plugora-cf7mc-panel');
    if (!panel) return;
    var sel       = panel.querySelector('.plugora-cf7mc-audience');
    var refresh   = panel.querySelector('.plugora-cf7mc-refresh');
    var status    = panel.querySelector('.plugora-cf7mc-audience-status');
    var fieldmap  = panel.querySelector('.plugora-cf7mc-fieldmap');
    var groupsBox = panel.querySelector('.plugora-cf7mc-groups');
    var emailSel  = panel.querySelector('#plugora_cf7mc_email_field');
    var nameSel   = panel.querySelector('#plugora_cf7mc_name_field');
    var mailTags  = Array.from(panel.querySelectorAll('#plugora_cf7mc_email_field option'))
                         .map(function(o){ return o.value; }).filter(Boolean);
    var currentMap    = parseJSON(fieldmap && fieldmap.dataset.current) || {};
    var currentInter  = parseJSON(groupsBox && groupsBox.dataset.current) || {};
    var isPro = cfg.isPremium;
    var lastLoaded = 0;
    var REFRESH_MS = 60 * 1000; // 60s — cheap because the REST endpoint is cached.

    // Auto-suggest email/name pickers if user hasn't picked anything yet.
    function autoSuggestCoreFields() {
      if (emailSel && !emailSel.value) {
        var em = bestMatch(mailTags, 'EMAIL', 'Email', 'email');
        if (em) {
          emailSel.value = em;
          emailSel.classList.add('plugora-auto-suggested');
        }
      }
      if (nameSel && !nameSel.value) {
        var nm = bestMatch(mailTags, 'FNAME', 'Name', 'text');
        if (nm) {
          nameSel.value = nm;
          nameSel.classList.add('plugora-auto-suggested');
        }
      }
    }

    function setStatus(text, cls){
      if (!status) return;
      status.textContent = text || '';
      status.className = 'plugora-cf7mc-audience-status' + (cls ? ' ' + cls : '');
    }

    function loadAudiences(force) {
      if (!sel) return Promise.resolve();
      setStatus(cfg.i18n.loading || 'Loading…');
      return api('/audiences' + (force ? '?force=1' : '')).then(function(r){
        if (!r.ok) { setStatus('✗ ' + (r.error || ''), 'err'); return; }
        var current = sel.value;
        sel.innerHTML = '<option value="">— Select a list —</option>';
        (r.audiences || []).forEach(function(a){
          var o = document.createElement('option');
          o.value = a.id;
          o.textContent = a.name + ' · ' + (a.members || 0).toLocaleString() + ' contacts';
          if (current === a.id) o.selected = true;
          sel.appendChild(o);
        });
        lastLoaded = Date.now();
        if (!(r.audiences || []).length) {
          setStatus(cfg.i18n.no_audiences || 'No audiences found.', 'warn');
        } else {
          setStatus('Updated ' + fmtTime(new Date()), 'ok');
        }
        if (sel.value) loadFields(sel.value);
      }).catch(function(){ setStatus('✗ Network error', 'err'); });
    }

    function loadFields(listId) {
      if (!fieldmap) return;
      fieldmap.innerHTML = '';
      fieldmap.appendChild(el('p', { class:'description', text: cfg.i18n.loading || 'Loading…' }));
      api('/audiences/' + encodeURIComponent(listId) + '/fields').then(function(r){
        if (!r.ok) {
          fieldmap.innerHTML = '';
          fieldmap.appendChild(el('p', { class:'description plugora-cf7mc-error', text: r.error || 'Could not load merge fields.' }));
          return;
        }
        renderFieldMap(r.merge_fields || []);
        renderGroups(r.groups || []);
      });
    }

    function fieldTypeBadge(type){
      if (!type) return null;
      return el('span', { class:'plugora-cf7mc-type-badge', text: type });
    }

    function renderFieldMap(fields) {
      fieldmap.innerHTML = '';
      var header = el('div', { class:'plugora-cf7mc-fieldmap-head' }, [
        el('strong', { text: 'Mailchimp merge fields' }),
        el('span', { class:'plugora-cf7mc-fieldmap-hint', text: 'We auto-suggest matches; adjust as needed.' }),
      ]);
      fieldmap.appendChild(header);

      if (!fields.length) {
        fieldmap.appendChild(el('p', { class:'description', text: 'This audience has no extra merge fields.' }));
        return;
      }

      var grid = el('div', { class:'plugora-cf7mc-fieldmap-grid' });
      fieldmap.appendChild(grid);
      var suggestionsApplied = 0;

      fields.forEach(function(f){
        var saved = currentMap[f.tag] || '';
        var picked = saved;
        var auto = '';
        if (!picked) {
          auto = bestMatch(mailTags, f.tag, f.name, f.type);
          if (auto) { picked = auto; suggestionsApplied++; }
        }

        var row = el('div', { class:'plugora-cf7mc-fieldmap-row' + (f.required ? ' is-required' : '') });

        var labelKids = [
          el('span', { class:'plugora-cf7mc-fieldmap-name', text: f.name }),
          el('code', { text: f.tag }),
        ];
        if (f.type) labelKids.push(fieldTypeBadge(f.type));
        if (f.required) labelKids.push(el('span', { class:'req', text: '*' }));
        var label = el('div', { class:'plugora-cf7mc-fieldmap-label' }, labelKids);

        var sel2 = el('select', { name: 'plugora_cf7mc[field_map][' + f.tag + ']' });
        sel2.appendChild(el('option', { value:'', text:'— Skip —' }));
        mailTags.forEach(function(t){
          var o = el('option', { value: t, text: t });
          if (picked === t) o.selected = true;
          sel2.appendChild(o);
        });
        if (auto && !saved) sel2.classList.add('plugora-auto-suggested');
        sel2.addEventListener('change', function(){
          sel2.classList.remove('plugora-auto-suggested');
        });

        var ctrl = el('div', { class:'plugora-cf7mc-fieldmap-ctrl' }, [sel2]);
        row.appendChild(label);
        row.appendChild(ctrl);
        grid.appendChild(row);
      });

      if (suggestionsApplied > 0) {
        var note = el('p', { class:'plugora-cf7mc-suggestion-note',
          text: '✨ Auto-mapped ' + suggestionsApplied + ' field' + (suggestionsApplied === 1 ? '' : 's') + ' from your form. Review before saving.' });
        fieldmap.insertBefore(note, grid);
      }
    }

    function renderGroups(groups) {
      if (!groupsBox) return;
      groupsBox.innerHTML = '';
      if (!groups.length) {
        groupsBox.appendChild(el('p', { class:'description', text: 'No interest groups on this audience.' }));
        return;
      }
      groups.forEach(function(g){
        var input = el('input', { type:'checkbox', name: 'plugora_cf7mc[interests][' + g.id + ']', value:'1' });
        if (currentInter[g.id]) input.checked = true;
        if (!isPro) input.disabled = true;
        var label = el('label', { class:'plugora-cf7mc-group-label' }, [input, document.createTextNode(' ' + g.name)]);
        if (!isPro) label.appendChild(el('span', { class:'plugora-pro-pill', text:'PRO' }));
        groupsBox.appendChild(el('div', { class:'plugora-cf7mc-fieldmap-row' }, [label]));
      });
    }

    if (refresh) refresh.addEventListener('click', function(e){
      e.preventDefault();
      loadAudiences(true);
    });
    if (sel) sel.addEventListener('change', function(){
      if (sel.value) loadFields(sel.value);
      else { fieldmap.innerHTML = ''; groupsBox.innerHTML = ''; }
    });

    // Auto-refresh when the user re-focuses the tab and the cache is stale.
    document.addEventListener('visibilitychange', function(){
      if (!document.hidden && Date.now() - lastLoaded > REFRESH_MS) loadAudiences(false);
    });

    autoSuggestCoreFields();
    loadAudiences(false);
  }

  bindKeyCheck();
  bindCF7Panel();
})();
