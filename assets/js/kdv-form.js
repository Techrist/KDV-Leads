/*
 * KDV Motors, moteur de formulaire multi-étapes (vanilla, ES2019).
 *
 * Balisage attendu :
 *   <div class="kdv-app" data-kdv-form="estimation">
 *     <ol data-kdv-stepper> <li class="kdv-stepper__item">…</li> … </ol>
 *     <form novalidate>
 *       <section data-kdv-step> <h2 data-kdv-heading tabindex="-1">…</h2>
 *         <div class="kdv-field" data-required data-validate="phone" data-min="2" data-max="120">
 *           <input name="…"> <p class="kdv-error" hidden></p>
 *         </div> …
 *       </section>
 *       <button type="button" data-kdv-prev>, <button type="button" data-kdv-next>, <button type="submit">
 *     </form>
 *   </div>
 *
 * Événements émis sur la racine : kdv:stepchange, kdv:submit (annulable : le serveur est géré par la phase 3).
 */
(function () {
  'use strict';

  var REDUCED = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  var MSG = {
    required: 'Ce champ est obligatoire.',
    choose: 'Veuillez faire un choix.',
    consent: 'Vous devez accepter pour continuer.',
    email: 'Saisissez une adresse e-mail valide.',
    phone: 'Saisissez un numéro de téléphone valide (ex : 06 12 34 56 78).',
    plate: 'Format d’immatriculation invalide (ex : AB-123-CD).',
    km: 'Saisissez un kilométrage entre 0 et 999 999.',
    min: function (n) { return 'Saisissez au moins ' + n + ' caractères.'; },
    max: function (n) { return 'Saisissez ' + n + ' caractères au maximum.'; }
  };

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  /* ---------- Validateurs (valeur normalisée -> message | '') ---------- */
  var validators = {
    email: function (v) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) ? '' : MSG.email;
    },
    phone: function (v, field) {
      var s = v.replace(/[\s.\-()]/g, '');
      if (/^(?:0|\+33|0033)[1-9]\d{8}$/.test(s)) { return ''; }
      var foreign = field.getAttribute('data-foreign') === '1';
      if (foreign && /^(?:\+|00)[1-9]\d{7,14}$/.test(s)) { return ''; }
      return MSG.phone;
    },
    plate: function (v) {
      var s = v.toUpperCase().replace(/[\s]/g, '');
      var siv = /^[A-HJ-NP-TV-Z]{2}-?\d{3}-?[A-HJ-NP-TV-Z]{2}$/;   // AA-123-AA (sans I, O, U)
      var fni = /^\d{1,4}-?[A-Z]{1,3}-?\d{2,3}$/;                  // ancien format
      return siv.test(s) || fni.test(s) ? '' : MSG.plate;
    },
    postal: function (v) {
      return /^\d{5}$/.test(v) ? '' : 'Saisissez un code postal à 5 chiffres.';
    },
    money: function (v) {
      var d = v.replace(/\s/g, '');
      return /^\d{1,7}$/.test(d) && parseInt(d, 10) > 0 ? '' : 'Saisissez un montant en euros valide.';
    },
    links: function (v) {
      var lines = v.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
      if (lines.length > 5) { return 'Cinq liens maximum.'; }
      var bad = lines.some(function (l) { return !/^https?:\/\/[^\s]+\.[^\s]+$/i.test(l); });
      return bad ? 'Chaque lien doit commencer par http:// ou https://.' : '';
    },
    km: function (v) {
      var n = parseInt(v.replace(/\s/g, ''), 10);
      return /^\d+$/.test(v.replace(/\s/g, '')) && n >= 0 && n <= 999999 ? '' : MSG.km;
    }
  };

  /* ---------- Formatage à la saisie ---------- */
  var formatters = {
    thousands: function (input) {
      var digits = input.value.replace(/\D/g, '').slice(0, 6);
      input.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    },
    thousands7: function (input) {
      var digits = input.value.replace(/\D/g, '').slice(0, 7);
      input.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    },
    plate: function (input, final) {
      var v = input.value.toUpperCase().replace(/[^A-Z0-9\-\s]/g, '');
      if (final) {
        var compact = v.replace(/[\s\-]/g, '');
        var m = /^([A-Z]{2})(\d{3})([A-Z]{2})$/.exec(compact);
        if (m) { v = m[1] + '-' + m[2] + '-' + m[3]; }
      }
      input.value = v;
    }
  };

  /* ---------- Aides de champ ---------- */
  function fieldInputs(field) {
    return $$('input:not([type=hidden]), select, textarea', field);
  }

  function fieldValue(field) {
    var inputs = fieldInputs(field);
    if (!inputs.length) { return ''; }
    var first = inputs[0];
    var chips = $('[data-chips-value]', field);
    if (chips) {            // widget à puces : la valeur est un tableau JSON
      try { return JSON.parse(chips.value || '[]').length ? 'on' : ''; } catch (e) { return ''; }
    }
    if (first.type === 'radio') {
      var checked = inputs.filter(function (i) { return i.checked; })[0];
      return checked ? checked.value : '';
    }
    if (first.type === 'checkbox') {
      return inputs.some(function (i) { return i.checked; }) ? 'on' : '';
    }
    return (first.value || '').trim();
  }

  function isActive(field) {
    if (field.closest('[hidden]')) { return false; }
    var inputs = fieldInputs(field);
    return inputs.length > 0 && inputs.some(function (i) { return !i.disabled; });
  }

  function errorNode(field) {
    var node = $('.kdv-error', field);
    if (!node) {
      node = document.createElement('p');
      node.className = 'kdv-error';
      node.hidden = true;
      field.appendChild(node);
    }
    if (!node.id) { node.id = 'kdv-err-' + Math.random().toString(36).slice(2, 9); }
    return node;
  }

  function setError(field, message) {
    var node = errorNode(field);
    var describedTarget = field.tagName === 'FIELDSET' ? [field] : fieldInputs(field);
    field.classList.toggle('has-error', !!message);
    node.textContent = message || '';
    node.hidden = !message;
    describedTarget.forEach(function (el) {
      if (message) {
        el.setAttribute('aria-invalid', 'true');
        el.setAttribute('aria-describedby', node.id);
      } else {
        el.removeAttribute('aria-invalid');
        el.removeAttribute('aria-describedby');
      }
    });
  }

  /** @returns {string} message d'erreur ou '' */
  function validateField(field) {
    if (!isActive(field)) { setError(field, ''); return ''; }
    var value = fieldValue(field);
    var message = '';
    var required = field.hasAttribute('data-required');
    var first = fieldInputs(field)[0];

    if (value === '') {
      if (required) {
        message = first.type === 'radio' ? MSG.choose : first.type === 'checkbox' ? MSG.consent : MSG.required;
      }
    } else {
      var rule = field.getAttribute('data-validate');
      if (rule && validators[rule]) { message = validators[rule](value, field); }
      var min = parseInt(field.getAttribute('data-min'), 10);
      var max = parseInt(field.getAttribute('data-max'), 10);
      if (!message && min && value.length < min) { message = MSG.min(min); }
      if (!message && max && value.length > max) { message = MSG.max(max); }
    }
    setError(field, message);
    return message;
  }

  /* ---------- Moteur ---------- */
  function KdvForm(root) {
    this.root = root;
    this.name = root.getAttribute('data-kdv-form') || 'form';
    this.form = $('form', root);
    this.steps = $$('[data-kdv-step]', root);
    this.stepperItems = $$('[data-kdv-stepper] > li', root);
    this.index = 0;
    this.draftKey = 'kdv_draft_' + this.name;
    this.live = $('[data-kdv-live]', root);
    if (!this.live) {
      this.live = document.createElement('div');
      this.live.className = 'kdv-sr-only';
      this.live.setAttribute('aria-live', 'polite');
      this.live.setAttribute('data-kdv-live', '');
      root.appendChild(this.live);
    }
    this.bind();
    this.restoreDraft();
    this.show(this.index, { focus: false, scroll: false, announce: false });
  }

  KdvForm.prototype.bind = function () {
    var self = this;

    $$('[data-kdv-next]', this.root).forEach(function (b) {
      b.addEventListener('click', function () { self.next(); });
    });
    $$('[data-kdv-prev]', this.root).forEach(function (b) {
      b.addEventListener('click', function () { self.prev(); });
    });

    // Entrée dans un champ : avance d'une étape, ne soumet qu'à la dernière.
    this.form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (self.index < self.steps.length - 1) { self.next(); } else { self.submit(); }
    });

    this.form.addEventListener('input', function (e) {
      var t = e.target;
      var fmt = t.getAttribute && t.getAttribute('data-format');
      if (fmt && formatters[fmt]) { formatters[fmt](t, false); }
      var field = t.closest && t.closest('.kdv-field');
      if (field && field.classList.contains('has-error')) { validateField(field); }
      self.saveDraft();
    });
    this.form.addEventListener('change', function (e) {
      var field = e.target.closest && e.target.closest('.kdv-field');
      if (field && field.classList.contains('has-error')) { validateField(field); }
      self.saveDraft();
    });
    this.form.addEventListener('focusout', function (e) {
      var t = e.target;
      var fmt = t.getAttribute && t.getAttribute('data-format');
      if (fmt && formatters[fmt]) { formatters[fmt](t, true); }
    });
  };

  KdvForm.prototype.validateStep = function (step) {
    var firstInvalid = null;
    $$('.kdv-field', step).forEach(function (field) {
      if (validateField(field) && !firstInvalid) { firstInvalid = field; }
    });
    if (firstInvalid) {
      var target = fieldInputs(firstInvalid).filter(function (i) { return !i.disabled; })[0];
      if (target) { target.focus(); }
      return false;
    }
    return true;
  };

  KdvForm.prototype.next = function () {
    if (!this.validateStep(this.steps[this.index])) { return; }
    if (this.index < this.steps.length - 1) { this.show(this.index + 1, { focus: true, scroll: true, announce: true }); }
  };

  KdvForm.prototype.prev = function () {
    if (this.index > 0) {
      this.show(this.index - 1, { focus: true, scroll: true, announce: true });
      return;
    }
    var home = this.root.getAttribute('data-home-url');
    if (window.history.length > 1 && document.referrer) { window.history.back(); }
    else if (home) { window.location.href = home; }
  };

  KdvForm.prototype.show = function (index, opts) {
    var self = this;
    this.index = Math.max(0, Math.min(index, this.steps.length - 1));
    this.steps.forEach(function (step, i) { step.hidden = i !== self.index; });
    this.root.setAttribute('data-step', String(this.index + 1));

    this.stepperItems.forEach(function (li, i) {
      li.classList.toggle('is-done', i < self.index);
      li.classList.toggle('is-current', i === self.index);
      if (i === self.index) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
      var status = $('.kdv-stepper__status', li);
      if (!status) {
        status = document.createElement('span');
        status.className = 'kdv-sr-only kdv-stepper__status';
        li.appendChild(status);
      }
      status.textContent = i < self.index ? ' (terminée)' : i === self.index ? ' (étape en cours)' : ' (à venir)';
    });

    // Dernière étape : CONTINUER laisse place au bouton d'envoi.
    var isLast = this.index === this.steps.length - 1;
    $$('[data-kdv-next]', this.root).forEach(function (b) { b.hidden = isLast; });
    $$('[data-kdv-submit], [data-kdv-last-only]', this.root).forEach(function (b) { b.hidden = !isLast; });

    var heading = $('[data-kdv-heading]', this.steps[this.index]);
    if (opts.scroll) {
      this.root.scrollIntoView({ behavior: REDUCED ? 'auto' : 'smooth', block: 'start' });
    }
    if (opts.focus && heading) { heading.focus({ preventScroll: true }); }
    if (opts.announce) {
      var label = this.stepperItems[this.index] ? this.stepperItems[this.index].textContent.replace(/\(.*?\)/g, '').trim() : '';
      this.live.textContent = 'Étape ' + (this.index + 1) + ' sur ' + this.steps.length + (label ? ' : ' + label : '');
    }
    this.saveDraft();
    this.root.dispatchEvent(new CustomEvent('kdv:stepchange', { bubbles: true, detail: { index: this.index } }));
  };

  /** Valide toutes les étapes ; renvoie l'index de la première invalide, ou -1. */
  KdvForm.prototype.firstInvalidStep = function () {
    var self = this;
    for (var i = 0; i < this.steps.length; i++) {
      var bad = $$('.kdv-field', this.steps[i]).some(function (f) { return validateField(f) !== ''; });
      if (bad) { return i; }
    }
    return -1;
  };

  KdvForm.prototype.submit = function () {
    var bad = this.firstInvalidStep();
    if (bad !== -1) {
      this.show(bad, { focus: false, scroll: true, announce: true });
      this.validateStep(this.steps[bad]);
      return;
    }
    var ev = new CustomEvent('kdv:submit', { bubbles: true, cancelable: true, detail: { form: this.form, data: this.serialize() } });
    this.root.dispatchEvent(ev);
  };

  KdvForm.prototype.serialize = function () {
    var out = {};
    $$('input, select, textarea', this.form).forEach(function (el) {
      if (!el.name || el.disabled) { return; }
      if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) { return; }
      if (/\[\]$/.test(el.name)) {
        var key = el.name.replace(/\[\]$/, '');
        (out[key] = out[key] || []).push(el.value);
      } else {
        out[el.name] = el.value;
      }
    });
    return out;
  };

  /* ---------- Brouillon (sessionStorage, jamais bloquant) ---------- */
  KdvForm.prototype.saveDraft = function () {
    try {
      var data = { step: this.index, values: {} };
      $$('input, select, textarea', this.form).forEach(function (el) {
        if (!el.name || el.hasAttribute('data-no-draft')) { return; }
        if (el.type === 'radio') { if (el.checked) { data.values[el.name] = el.value; } }
        else if (el.type === 'checkbox') {
          var k = el.name; (data.values[k] = data.values[k] || []);
          if (el.checked) { data.values[k].push(el.value); }
        } else { data.values[el.name] = el.value; }
      });
      window.sessionStorage.setItem(this.draftKey, JSON.stringify(data));
    } catch (e) { /* stockage indisponible : on continue sans brouillon */ }
  };

  KdvForm.prototype.restoreDraft = function () {
    var raw;
    try { raw = window.sessionStorage.getItem(this.draftKey); } catch (e) { return; }
    if (!raw) { return; }
    var data;
    try { data = JSON.parse(raw); } catch (e) { return; }
    if (!data || typeof data.values !== 'object') { return; }

    $$('input, select, textarea', this.form).forEach(function (el) {
      if (!el.name || el.hasAttribute('data-no-draft') || !(el.name in data.values)) { return; }
      var v = data.values[el.name];
      if (el.type === 'radio') { el.checked = el.value === v; }
      else if (el.type === 'checkbox') { el.checked = Array.isArray(v) && v.indexOf(el.value) !== -1; }
      else { el.value = v; }
    });
    // Laisse les scripts dépendants (marque -> modèle) se resynchroniser.
    $$('select, input', this.form).forEach(function (el) {
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    if (typeof data.step === 'number') { this.index = Math.max(0, Math.min(data.step, this.steps.length - 1)); }
  };

  KdvForm.prototype.clearDraft = function () {
    try { window.sessionStorage.removeItem(this.draftKey); } catch (e) { /* ignore */ }
  };

  /* ---------- Envoi partagé (jeton d'idempotence, jamais de faux succès) ---------- */
  function captureSource() {
    var key = 'kdv_source';
    try {
      var saved = window.sessionStorage.getItem(key);
      if (saved) { return JSON.parse(saved); }
    } catch (e) { /* ignore */ }
    var q = new URLSearchParams(window.location.search);
    var src = {
      utm_source: q.get('utm_source') || '', utm_medium: q.get('utm_medium') || '',
      utm_campaign: q.get('utm_campaign') || '', utm_content: q.get('utm_content') || '',
      gclid: q.has('gclid') ? 1 : 0, fbclid: q.has('fbclid') ? 1 : 0,
      referrer: document.referrer || '', landing: window.location.pathname
    };
    try { window.sessionStorage.setItem(key, JSON.stringify(src)); } catch (e) { /* ignore */ }
    return src;
  }

  function uuid() {
    if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16);
    });
  }

  /**
   * cfg: { endpoint, nonce, thanksUrl, consentVersion, jsonFields: [champs contenant un tableau JSON], extra: fn(data) }
   */
  function setupSubmit(root, cfg) {
    var form = $('form', root);
    var name = root.getAttribute('data-kdv-form');
    var started = Date.now();
    var source = captureSource();
    var submitBtn = $('[data-kdv-submit]', root);
    var label = $('[data-kdv-submit-label]', root);
    var idleLabel = label ? label.textContent : '';
    var alertBox = $('[data-kdv-alert]', root);
    var keyName = 'kdv_submission_key_' + name;
    var fallbackKey = null;
    var sending = false;

    function submissionKey() {
      try {
        var k = window.sessionStorage.getItem(keyName);
        if (!k) { k = uuid(); window.sessionStorage.setItem(keyName, k); }
        return k;
      } catch (e) { return fallbackKey || (fallbackKey = uuid()); }
    }
    function showError(msg) { alertBox.textContent = msg; alertBox.hidden = false; }
    function setSending(on) {
      sending = on;
      submitBtn.disabled = on;
      submitBtn.setAttribute('aria-busy', on ? 'true' : 'false');
      if (label) { label.textContent = on ? 'Envoi en cours…' : idleLabel; }
    }

    root.addEventListener('kdv:submit', function (e) {
      e.preventDefault();
      if (sending) { return; }
      alertBox.hidden = true;
      var blocked = cfg.beforeSubmit ? cfg.beforeSubmit() : '';
      if (blocked) { showError(blocked); return; }

      var data = e.detail.data;
      (cfg.jsonFields || []).forEach(function (n) {
        try { data[n] = JSON.parse(data[n] || '[]'); } catch (err) { data[n] = []; }
      });
      data.submission_key = submissionKey();
      data.elapsed_ms = Date.now() - started;
      data.source = source;
      data.consent_version = cfg.consentVersion || '';
      if (cfg.extra) { cfg.extra(data); }

      setSending(true);
      fetch(cfg.endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
        body: JSON.stringify(data)
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (body) { return { ok: res.ok, body: body }; });
      }).then(function (r) {
        if (r.ok && r.body && r.body.success) {
          var inst = window.KdvForm.get(root);
          if (inst) { inst.clearDraft(); }
          try {
            window.sessionStorage.removeItem(keyName);
            // Lu une seule fois par la page merci : sans ce témoin, aucune conversion n'est déclarée.
            window.sessionStorage.setItem('kdv_conversion', JSON.stringify({ type: name, t: Date.now(), ref: (r.body && r.body.reference) || '' }));
          } catch (err) { /* ignore */ }
          window.location.href = cfg.thanksUrl;
          return;
        }
        // Refus serveur : erreurs par champ, jamais de faux succès.
        setSending(false);
        var errs = (r.body && r.body.errors) || {};
        var firstStepField = null;
        Object.keys(errs).forEach(function (n) {
          var el = form.elements[n] || form.elements[n + '[]'];
          var node = el && (el.length && !el.tagName ? el[0] : el);
          var field = node && node.closest ? node.closest('.kdv-field') : $('[data-field="' + n + '"]', root);
          if (field) {
            var err = errorNode(field);
            err.textContent = errs[n]; err.hidden = false; field.classList.add('has-error');
            if (!firstStepField) { firstStepField = field; }
          }
        });
        // Ramène l'utilisateur à l'étape qui contient la première erreur.
        var inst2 = window.KdvForm.get(root);
        var section = firstStepField && firstStepField.closest('[data-kdv-step]');
        if (inst2 && section) {
          var idx = inst2.steps.indexOf(section);
          if (idx !== -1 && idx !== inst2.index) { inst2.show(idx, { focus: true, scroll: true, announce: true }); }
        }
        showError((r.body && r.body.success === false && r.body.message) || 'Votre demande n’a pas pu être enregistrée. Vérifiez les informations saisies et réessayez.');
      }).catch(function () {
        setSending(false);
        showError('Connexion impossible. Vos informations sont conservées : vérifiez votre connexion puis réessayez.');
      });
    });
  }

  /* ---------- API publique ---------- */
  var instances = [];
  window.KdvForm = {
    setupSubmit: setupSubmit,
    uuid: uuid,
    validators: validators,
    formatters: formatters,
    get: function (root) {
      for (var i = 0; i < instances.length; i++) { if (instances[i].root === root) { return instances[i]; } }
      return null;
    }
  };

  function init() {
    $$('[data-kdv-form]').forEach(function (root) {
      if (!$('form', root) || window.KdvForm.get(root)) { return; }
      instances.push(new KdvForm(root));
    });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
