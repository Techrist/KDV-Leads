/*
 * Envoi de photos : redimensionnement navigateur (canvas, 1600 px, JPEG ~0,8) puis envoi un par un
 * avec progression. Le serveur revérifie, nettoie (EXIF/GPS) et réencode chaque image.
 * Repli : si le navigateur ne sait pas décoder l'image, le fichier est envoyé tel quel.
 */
(function () {
  'use strict';

  var CFG = window.KDV_CONFIG || {};
  var MAX_SIDE = 1600;

  function api(path) { return (CFG.uploadBase || '').replace(/\/$/, '') + path; }

  function resize(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.URL || !document.createElement('canvas').toBlob) { resolve(file); return; }
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        var w = img.naturalWidth, h = img.naturalHeight;
        var scale = Math.min(1, MAX_SIDE / Math.max(w, h));
        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(w * scale));
        canvas.height = Math.max(1, Math.round(h * scale));
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';                    // fond blanc pour les PNG transparents
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) { resolve(blob && blob.size < file.size ? blob : file); }, 'image/jpeg', 0.8);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function Uploader(root) {
    this.root = root;
    this.max = parseInt(root.getAttribute('data-max'), 10) || 5;
    this.input = root.querySelector('input[type=file]');
    this.list = root.querySelector('[data-upload-list]');
    this.errorNode = root.querySelector('[data-upload-error]');
    this.items = [];
    this.tokenPromise = null;
    this.token = '';
    var self = this;
    this.input.addEventListener('change', function () {
      self.add(Array.prototype.slice.call(self.input.files));
      self.input.value = '';
    });
  }

  Uploader.prototype.error = function (msg) {
    this.errorNode.textContent = msg || '';
    this.errorNode.hidden = !msg;
  };

  Uploader.prototype.session = function () {
    var self = this;
    if (!this.tokenPromise) {
      this.tokenPromise = fetch(api('/upload-session'), {
        method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce || '' }
      }).then(function (r) { return r.json(); }).then(function (b) {
        if (!b || !b.token) { throw new Error('session'); }
        self.token = b.token;
        return b.token;
      }).catch(function (e) { self.tokenPromise = null; throw e; });
    }
    return this.tokenPromise;
  };

  Uploader.prototype.add = function (files) {
    var self = this;
    this.error('');
    var room = this.max - this.items.length;
    if (files.length > room) { this.error('Vous pouvez ajouter ' + this.max + ' photos au maximum.'); }
    files.slice(0, Math.max(0, room)).forEach(function (file) {
      if (CFG.maxPhotoMb && file.size > CFG.maxPhotoMb * 1024 * 1024 * 3) { self.error('« ' + file.name + ' » est trop volumineuse.'); return; }
      var item = { file: file, serverId: 0, state: 'sending', progress: 0, preview: window.URL ? URL.createObjectURL(file) : '' };
      self.items.push(item);
      self.render();
      self.send(item);
    });
  };

  Uploader.prototype.send = function (item) {
    var self = this;
    Promise.all([this.session(), resize(item.file)]).then(function (r) {
      return new Promise(function (resolve, reject) {
        var fd = new FormData();
        fd.append('token', r[0]);
        fd.append('photo', r[1], 'photo.jpg');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', api('/uploads'));
        xhr.setRequestHeader('X-WP-Nonce', CFG.nonce || '');
        xhr.upload.onprogress = function (e) {
          if (e.lengthComputable) { item.progress = Math.round(e.loaded / e.total * 100); self.render(); }
        };
        xhr.onload = function () {
          var body = {};
          try { body = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
          if (xhr.status >= 200 && xhr.status < 300 && body.success) { resolve(body); } else { reject(new Error(body.message || 'Échec de l’envoi.')); }
        };
        xhr.onerror = function () { reject(new Error('Connexion impossible.')); };
        xhr.send(fd);
      });
    }).then(function (body) {
      item.serverId = body.id; item.state = 'done'; item.progress = 100;
      self.render();
    }).catch(function (e) {
      item.state = 'error'; item.message = e.message || 'Échec de l’envoi.';
      self.render();
    });
  };

  Uploader.prototype.remove = function (item) {
    var self = this;
    this.items = this.items.filter(function (i) { return i !== item; });
    if (item.preview && window.URL) { URL.revokeObjectURL(item.preview); }
    if (item.serverId && this.token) {
      fetch(api('/uploads/' + item.serverId), {
        method: 'DELETE', credentials: 'same-origin',
        headers: { 'X-WP-Nonce': CFG.nonce || '', 'X-KDV-Upload-Token': this.token }
      }).catch(function () { /* nettoyé par le cron */ });
    }
    this.error('');
    this.render();
    this.input.focus();
  };

  Uploader.prototype.render = function () {
    var self = this;
    this.list.innerHTML = '';
    this.items.forEach(function (item) {
      var li = document.createElement('li');
      li.className = 'kdv-upload__item' + (item.state === 'error' ? ' is-error' : '');
      var img = document.createElement('img');
      img.alt = ''; img.src = item.preview;
      var info = document.createElement('div');
      info.className = 'kdv-upload__info';
      var name = document.createElement('span');
      name.className = 'kdv-upload__name'; name.textContent = item.file.name;
      var status = document.createElement('span');
      status.className = 'kdv-upload__status';
      status.textContent = item.state === 'done' ? 'Envoyée' : item.state === 'error' ? item.message : 'Envoi… ' + item.progress + ' %';
      var bar = document.createElement('progress');
      bar.max = 100; bar.value = item.progress; bar.hidden = item.state !== 'sending';
      bar.setAttribute('aria-label', 'Progression de ' + item.file.name);
      info.appendChild(name); info.appendChild(status); info.appendChild(bar);
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'kdv-upload__remove';
      btn.setAttribute('aria-label', 'Supprimer ' + item.file.name);
      btn.textContent = '×';
      btn.addEventListener('click', function () { self.remove(item); });
      li.appendChild(img); li.appendChild(info); li.appendChild(btn);
      self.list.appendChild(li);
    });
  };

  Uploader.prototype.pending = function () {
    return this.items.some(function (i) { return i.state === 'sending'; });
  };

  Uploader.prototype.photos = function () {
    return this.items.filter(function (i) { return i.state === 'done'; }).map(function (i) { return { file_id: i.serverId, angle: '' }; });
  };

  window.KdvUploader = {
    instances: [],
    init: function () {
      var nodes = document.querySelectorAll('[data-kdv-uploader]');
      for (var i = 0; i < nodes.length; i++) { window.KdvUploader.instances.push(new Uploader(nodes[i])); }
    },
    first: function () { return window.KdvUploader.instances[0] || null; }
  };

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', window.KdvUploader.init); } else { window.KdvUploader.init(); }
})();
