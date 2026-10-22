/*
 * Parcours recherche : widgets à puces (marques/modèles, options) + envoi.
 * Dépend de kdv-form.js, kdv-uploader.js et window.KDV_CONFIG.
 */
(function () {
  'use strict';

  var CFG = window.KDV_CONFIG || {};
  var root = document.querySelector('[data-kdv-form="recherche"]');
  if (!root) { return; }

  /* ---------- Puces : valeur = tableau JSON dans un champ caché ---------- */
  function Chips(el) {
    var self = this;
    this.el = el;
    this.max = parseInt(el.getAttribute('data-max'), 10) || 10;
    this.list = el.querySelector('[data-chips-list]');
    this.input = el.querySelector('input[type=text]');
    this.hidden = el.querySelector('[data-chips-value]');
    this.suggest = Array.prototype.slice.call(el.querySelectorAll('[data-chips-suggest]'));
    this.values = [];

    el.querySelector('[data-chips-add]').addEventListener('click', function () { self.commit(); self.input.focus(); });
    this.input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); self.commit(); }
    });
    this.input.addEventListener('blur', function () { self.commit(); });
    this.suggest.forEach(function (b) {
      b.addEventListener('click', function () {
        var v = b.getAttribute('data-chips-suggest');
        if (self.values.indexOf(v) === -1) { self.add(v); } else { self.remove(v); }
      });
    });
    // Restauration du brouillon : la valeur cachée change de l'extérieur.
    this.hidden.addEventListener('change', function () { self.load(); });
    this.load();
  }

  Chips.prototype.load = function () {
    try { this.values = JSON.parse(this.hidden.value || '[]'); } catch (e) { this.values = []; }
    if (!Array.isArray(this.values)) { this.values = []; }
    this.render(false);
  };

  Chips.prototype.commit = function () {
    var v = this.input.value.replace(/\s+/g, ' ').trim();
    if (v) { this.add(v); }
    this.input.value = '';
  };

  Chips.prototype.add = function (v) {
    var lower = v.toLowerCase();
    if (this.values.length >= this.max || this.values.some(function (x) { return x.toLowerCase() === lower; })) { return; }
    this.values.push(v);
    this.render(true);
  };

  Chips.prototype.remove = function (v) {
    this.values = this.values.filter(function (x) { return x !== v; });
    this.render(true);
  };

  Chips.prototype.render = function (notify) {
    var self = this;
    this.list.innerHTML = '';
    this.values.forEach(function (v) {
      var li = document.createElement('li');
      li.className = 'kdv-chip';
      var t = document.createElement('span'); t.textContent = v;
      var b = document.createElement('button');
      b.type = 'button'; b.setAttribute('aria-label', 'Retirer ' + v); b.textContent = '×';
      b.addEventListener('click', function () { self.remove(v); self.input.focus(); });
      li.appendChild(t); li.appendChild(b);
      self.list.appendChild(li);
    });
    this.suggest.forEach(function (b) {
      var on = self.values.indexOf(b.getAttribute('data-chips-suggest')) !== -1;
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.classList.toggle('is-on', on);
    });
    this.hidden.value = JSON.stringify(this.values);
    if (notify) {
      // Déclenche le brouillon et efface l'erreur éventuelle.
      this.hidden.dispatchEvent(new Event('input', { bubbles: true }));
      var field = this.el.closest('.kdv-field');
      if (field && field.classList.contains('has-error')) { this.hidden.dispatchEvent(new Event('change', { bubbles: true })); }
    }
  };

  Array.prototype.slice.call(root.querySelectorAll('[data-kdv-chips]')).forEach(function (el) { new Chips(el); });

  /* ---------- Envoi ---------- */
  window.KdvForm.setupSubmit(root, {
    endpoint: CFG.endpoint,
    nonce: CFG.nonce,
    thanksUrl: CFG.thanksUrl,
    consentVersion: CFG.consentVersion,
    jsonFields: ['marques_modeles', 'options_indispensables', 'options_souhaitables'],
    beforeSubmit: function () {
      var up = window.KdvUploader && window.KdvUploader.first();
      return up && up.pending() ? 'Vos photos sont en cours d’envoi. Patientez quelques secondes puis réessayez.' : '';
    },
    extra: function (data) {
      ['carburant', 'carrosserie', 'origine_acceptee'].forEach(function (n) { if (!data[n]) { data[n] = []; } });
      data.liens_annonces = (data.liens_annonces || '').split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
      var up = window.KdvUploader && window.KdvUploader.first();
      data.photos = up ? up.photos() : [];
      data.upload_token = up ? up.token : '';
    }
  });
})();
