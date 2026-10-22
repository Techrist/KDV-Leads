/* FAQ : une seule question ouverte à la fois (repli pour les navigateurs sans <details name>). */
(function () {
  'use strict';
  document.addEventListener('toggle', function (e) {
    var d = e.target;
    if (!d || d.tagName !== 'DETAILS' || !d.open || !d.closest('[data-kdv-faq]')) { return; }
    var all = d.closest('[data-kdv-faq]').querySelectorAll('details.kdv-faq__item');
    for (var i = 0; i < all.length; i++) {
      if (all[i] !== d) { all[i].open = false; }
    }
  }, true);
})();
