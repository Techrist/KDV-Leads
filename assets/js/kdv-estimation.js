/*
 * Parcours estimation : marque -> modèle, champs « Autre », source marketing,
 * envoi au serveur (jeton d'idempotence, jamais de faux succès).
 * Dépend de kdv-form.js et de window.KDV_CONFIG (injecté par PHP).
 */
(function () {
  'use strict';

  var CFG = window.KDV_CONFIG || {};
  var root = document.querySelector('[data-kdv-form="estimation"]');
  if (!root) { return; }

  var form = root.querySelector('form');
  var $ = function (sel) { return root.querySelector(sel); };
  var started = Date.now();

  /* ---------- Marque / modèle ---------- */
  var marque = $('#kdv-marque');
  var modele = $('#kdv-modele');
  var marqueAutre = $('[data-other-for="marque"]');
  var modeleAutre = $('[data-other-for="modele"]');
  var modeleField = $('[data-model-select]');

  function toggleOther(field, show) {
    var input = field.querySelector('input');
    field.hidden = !show;
    input.disabled = !show;
    if (!show) { input.value = ''; }
  }

  function fillModels(brand) {
    var models = (CFG.vehicles && CFG.vehicles[brand]) || [];
    modele.innerHTML = '';
    var first = document.createElement('option');
    first.value = ''; first.textContent = 'Sélectionnez un modèle';
    modele.appendChild(first);
    if (!brand) { return; }
    models.concat(['Autre']).forEach(function (m) {
      var o = document.createElement('option');
      o.value = m === 'Autre' ? 'autre' : m;
      o.textContent = m;
      modele.appendChild(o);
    });
  }

  function syncVehicle() {
    var brand = marque.value;
    var isOther = brand === 'autre';
    toggleOther(marqueAutre, isOther);
    toggleOther(modeleAutre, false);
    if (isOther) {            // marque inconnue : le modèle est saisi librement
      modeleField.hidden = true;
      modele.disabled = true;
      toggleOther(modeleAutre, true);
      return;
    }
    modeleField.hidden = false;
    fillModels(brand);
    modele.disabled = brand === '';
  }

  // Le brouillon restaure le modèle avant que la liste existe : on le réapplique après le changement de marque.
  var pendingModel = null;
  try {
    var draft = JSON.parse(window.sessionStorage.getItem('kdv_draft_estimation') || 'null');
    if (draft && draft.values) { pendingModel = draft.values.modele || null; }
  } catch (e) { /* ignore */ }

  marque.addEventListener('change', function () {
    syncVehicle();
    if (pendingModel && marque.value && marque.value !== 'autre') {
      modele.value = pendingModel;
      toggleOther(modeleAutre, modele.value === 'autre');
    }
    pendingModel = null;
  });
  modele.addEventListener('change', function () { toggleOther(modeleAutre, modele.value === 'autre'); });
  syncVehicle();

  /* ---------- Envoi ---------- */
  window.KdvForm.setupSubmit(root, {
    endpoint: CFG.endpoint,
    nonce: CFG.nonce,
    thanksUrl: CFG.thanksUrl,
    consentVersion: CFG.consentVersion,
    extra: function (data) { if (!data.equipements) { data.equipements = []; } }
  });
})();
