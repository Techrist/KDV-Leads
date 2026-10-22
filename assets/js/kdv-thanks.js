/*
 * Page « merci » :
 *  - affiche la référence du dossier (simple confort, sans donnée personnelle) ;
 *  - déclare UNE conversion, uniquement si le formulaire vient d'aboutir (témoin posé par kdv-form.js
 *    après la réponse « success » du serveur). Une visite directe, un rechargement ou un témoin périmé
 *    ne déclenchent rien. Aucune donnée personnelle n'est transmise (type de demande seulement).
 * Le bandeau de consentement du site reste maître : on pousse dans dataLayer (GTM / Consent Mode) et on
 * n'appelle gtag / fbq que s'ils ont déjà été chargés par le site.
 */
(function () {
  'use strict';

  // Accessibilité : le titre reçoit le focus pour annoncer la confirmation.
  var heading = document.querySelector('.kdv-thanks__title');
  if (heading) { try { heading.focus({ preventScroll: true }); } catch (e) { /* ignore */ } }

  var flag = null;
  try {
    flag = JSON.parse(window.sessionStorage.getItem('kdv_conversion') || 'null');
    window.sessionStorage.removeItem('kdv_conversion');   // consommé : un seul déclenchement
  } catch (e) { return; }

  if (!flag || !flag.t || Date.now() - flag.t > 10 * 60 * 1000) { return; }

  if (flag.ref && /^KDV-(EST|REC)-\d{8}-\d{4,}$/.test(flag.ref)) {
    var box = document.querySelector('[data-kdv-ref]');
    if (box) { box.querySelector('strong').textContent = flag.ref; box.hidden = false; }
  }

  var leadType = flag.type === 'recherche' ? 'recherche' : 'estimation';

  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push({ event: 'kdv_lead', lead_type: leadType });

  if (typeof window.gtag === 'function') { window.gtag('event', 'generate_lead', { lead_type: leadType }); }
  if (typeof window.fbq === 'function') { window.fbq('track', 'Lead', { content_name: leadType }); }
})();
