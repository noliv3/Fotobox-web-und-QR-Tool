(() => {
  'use strict';

  const toastRoot = document.getElementById('toast-root');
  let longPressFired = false;
  let pressTimer = null;
  let pressStart = null;
  let pressTarget = null;

  function parseJsonResponse(response) {
    if (!response.ok) {
      throw new Error('HTTP ' + response.status);
    }

    const contentType = (response.headers.get('content-type') || '').toLowerCase();
    if (!contentType.includes('application/json')) {
      throw new Error('INVALID_CONTENT_TYPE');
    }

    return response.json();
  }

  function postForm(url, payload) {
    const csrfMeta = document.querySelector('meta[name=\"csrf-token\"]');
    const csrfToken = csrfMeta ? (csrfMeta.getAttribute('content') || '').trim() : '';
    const body = new URLSearchParams(payload);
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-Token': csrfToken
      },
      body,
      credentials: 'same-origin'
    }).then(parseJsonResponse).catch(() => ({ ok: false }));
  }

  function showToast(message, options = {}) {
    if (!toastRoot) return;
    toastRoot.innerHTML = '';

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');

    const text = document.createElement('span');
    text.textContent = message;
    toast.appendChild(text);

    if (options.actionText && typeof options.actionCallback === 'function') {
      const action = document.createElement('button');
      action.type = 'button';
      action.className = 'toast-action';
      action.textContent = options.actionText;
      action.addEventListener('click', () => {
        options.actionCallback();
      });
      toast.appendChild(action);
    }

    toastRoot.appendChild(toast);
    window.setTimeout(() => {
      toast.classList.add('is-hiding');
      window.setTimeout(() => {
        if (toast.parentNode === toastRoot) toastRoot.removeChild(toast);
      }, 200);
    }, 1200);
  }

  function setFavState(el, isFav) {
    el.classList.toggle('is-fav', isFav);
  }

  function toggleFavById(photoId, done) {
    postForm('/mobile/api_mark.php', { action: 'toggle', id: photoId }).then((res) => {
      if (!res || res.ok !== true) {
        showToast('Fehler aufgetreten');
        return;
      }
      done(res.state);
    });
  }

  function clearPress() {
    if (pressTimer) {
      window.clearTimeout(pressTimer);
      pressTimer = null;
    }
    pressStart = null;
    pressTarget = null;
  }

  function bindTileLongPress(tile) {
    const photoId = tile.getAttribute('data-photo-id');
    const link = tile.querySelector('.tile-link');
    if (!photoId || !link) return;

    tile.addEventListener('pointerdown', (ev) => {
      if (ev.pointerType === 'mouse' && ev.button !== 0) return;
      longPressFired = false;
      pressStart = { x: ev.clientX, y: ev.clientY };
      pressTarget = tile;
      pressTimer = window.setTimeout(() => {
        if (pressTarget !== tile) return;
        longPressFired = true;
        ev.preventDefault();
        toggleFavById(photoId, (state) => {
          const isFav = state === 'added';
          setFavState(tile, isFav);
          showToast(isFav ? 'Gemerkt' : 'Entfernt', !isFav ? {
            actionText: 'Rückgängig',
            actionCallback: () => {
              postForm('/mobile/api_mark.php', { action: 'add', id: photoId }).then(() => {
                setFavState(tile, true);
                showToast('Gemerkt');
              });
            }
          } : {});
        });
      }, 450);
    });

    tile.addEventListener('pointermove', (ev) => {
      if (!pressStart || pressTarget !== tile) return;
      const dx = ev.clientX - pressStart.x;
      const dy = ev.clientY - pressStart.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist > 10) {
        clearPress();
      }
    });

    tile.addEventListener('pointerup', clearPress);
    tile.addEventListener('pointercancel', clearPress);
    tile.addEventListener('pointerleave', clearPress);

    link.addEventListener('click', (ev) => {
      if (!longPressFired) return;
      ev.preventDefault();
      ev.stopPropagation();
      longPressFired = false;
    });

    tile.addEventListener('contextmenu', (ev) => {
      if (!longPressFired) return;
      ev.preventDefault();
      longPressFired = false;
    });
  }

  function bindMenu() {
    const toggle = document.querySelector('[data-menu-button]');
    const overlay = document.querySelector('[data-menu-overlay]');
    if (!toggle || !overlay) return;
    const panel = overlay.querySelector('.menu-panel');

    toggle.addEventListener('click', () => {
      overlay.hidden = !overlay.hidden;
      toggle.setAttribute('aria-expanded', overlay.hidden ? 'false' : 'true');
    });

    overlay.addEventListener('click', (ev) => {
      if (panel && panel.contains(ev.target)) return;
      overlay.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    });

    if (panel) {
      panel.querySelectorAll('a').forEach((a) => {
        a.addEventListener('click', () => {
          overlay.hidden = true;
          toggle.setAttribute('aria-expanded', 'false');
        });
      });
    }

    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') {
        overlay.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }


  function bindPrintForms() {
    document.querySelectorAll('[data-print-form]').forEach((form) => {
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const body = new URLSearchParams(new FormData(form));
        fetch('/mobile/api_print.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
          },
          body,
          credentials: 'same-origin'
        }).then((res) => {
          if (!res.ok) {
            return res.json().catch(() => ({})).then((payload) => ({ ok: false, status: res.status, payload }));
          }
          return res.json().then((payload) => ({ ok: true, payload }));
        }).then((result) => {
          if (result.ok && result.payload && result.payload.ok === true) {
            showToast('In Warteschlange');
            return;
          }
          const errorCode = (result.payload && result.payload.error) || '';
          if (errorCode === 'queue_full') {
            showToast('Warteschlange voll');
            return;
          }
          showToast('Druck derzeit nicht möglich');
        }).catch(() => {
          showToast('Druck derzeit nicht möglich');
        });
      });
    });
    document.querySelectorAll('[data-print-favs-form]').forEach((form) => {
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const body = new URLSearchParams(new FormData(form));
        fetch('/mobile/api_print_favs.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
          },
          body,
          credentials: 'same-origin'
        }).then((res) => {
          if (!res.ok) {
            return res.json().catch(() => ({})).then((payload) => ({ ok: false, payload }));
          }
          return res.json().then((payload) => ({ ok: true, payload }));
        }).then((result) => {
          if (result.ok && result.payload && result.payload.ok === true) {
            showToast('2 Druckjobs angelegt');
            return;
          }
          const errorCode = (result.payload && result.payload.error) || '';
          if (errorCode === 'need_two_new_favs') {
            showToast('Mindestens 2 neue gemerkte Bilder nötig');
            return;
          }
          if (errorCode === 'queue_full') {
            showToast('Warteschlange voll');
            return;
          }
          showToast('Druck derzeit nicht möglich');
        }).catch(() => {
          showToast('Druck derzeit nicht möglich');
        });
      });
    });
  }



  function bindPhotoTools() {
    const stage = document.querySelector('[data-photo-stage]');
    const image = document.querySelector('[data-detail-image]');
    if (!stage || !image) return;

    const zoomSlider = document.querySelector('[data-photo-zoom-slider]');
    const btnZoomIn = document.querySelector('[data-photo-zoom-in]');
    const btnZoomOut = document.querySelector('[data-photo-zoom-out]');
    const btnRotateLeft = document.querySelector('[data-photo-rotate-left]');
    const btnRotateRight = document.querySelector('[data-photo-rotate-right]');
    const btnReset = document.querySelector('[data-photo-reset]');
    const btnFullscreen = document.querySelector('[data-photo-fullscreen]');

    let zoom = 1;
    let rotation = 0;

    const clampZoom = (value) => Math.min(3, Math.max(1, value));
    const applyTransform = () => {
      image.style.transform = 'scale(' + zoom.toFixed(2) + ') rotate(' + rotation + 'deg)';
      if (zoomSlider) zoomSlider.value = zoom.toFixed(1);
    };

    if (zoomSlider) {
      zoomSlider.addEventListener('input', () => {
        zoom = clampZoom(Number(zoomSlider.value || '1'));
        applyTransform();
      });
    }

    if (btnZoomIn) {
      btnZoomIn.addEventListener('click', () => {
        zoom = clampZoom(zoom + 0.1);
        applyTransform();
      });
    }

    if (btnZoomOut) {
      btnZoomOut.addEventListener('click', () => {
        zoom = clampZoom(zoom - 0.1);
        applyTransform();
      });
    }

    if (btnRotateLeft) {
      btnRotateLeft.addEventListener('click', () => {
        rotation -= 90;
        applyTransform();
      });
    }

    if (btnRotateRight) {
      btnRotateRight.addEventListener('click', () => {
        rotation += 90;
        applyTransform();
      });
    }

    if (btnReset) {
      btnReset.addEventListener('click', () => {
        zoom = 1;
        rotation = 0;
        applyTransform();
      });
    }

    if (btnFullscreen) {
      btnFullscreen.addEventListener('click', () => {
        if (document.fullscreenElement) {
          document.exitFullscreen().catch(() => {});
          return;
        }
        stage.requestFullscreen?.().catch(() => {
          showToast('Vollbild nicht verfügbar');
        });
      });
    }

    applyTransform();
  }

  function bindOrderForm() {
    const form = document.querySelector('[data-order-form]');
    if (!form) return;

    const toggle = form.querySelector('[data-shipping-toggle]');
    const fields = form.querySelector('[data-shipping-fields]');
    if (!toggle || !fields) return;

    const inputs = fields.querySelectorAll('input');
    const update = () => {
      const enabled = !!toggle.checked;
      fields.hidden = !enabled;
      inputs.forEach((input) => {
        input.required = enabled;
      });
    };

    toggle.addEventListener('change', update);
    update();
  }

  function bindFavButtons() {
    document.querySelectorAll('[data-fav-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const photoId = btn.getAttribute('data-photo-id');
        if (!photoId) return;
        toggleFavById(photoId, (state) => {
          const isFav = state === 'added';
          btn.textContent = isFav ? 'Gemerkt' : 'Merken';
          showToast(isFav ? 'Gemerkt' : 'Entfernt');
        });
      });
    });

    document.querySelectorAll('[data-fav-remove]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const photoId = btn.getAttribute('data-photo-id');
        if (!photoId) return;
        postForm('/mobile/api_mark.php', { action: 'remove', id: photoId }).then((res) => {
          if (!res || res.ok !== true) {
            showToast('Fehler aufgetreten');
            return;
          }
          const tile = btn.closest('.tile');
          if (tile) tile.remove();
          showToast('Entfernt', {
            actionText: 'Rückgängig',
            actionCallback: () => {
              postForm('/mobile/api_mark.php', { action: 'add', id: photoId }).then(() => {
                window.location.reload();
              });
            }
          });
        });
      });
    });
  }

  bindMenu();
  bindFavButtons();
  bindPrintForms();
  bindOrderForm();
  bindPhotoTools();
  document.querySelectorAll('[data-photo-tile]').forEach(bindTileLongPress);

  window.showToast = showToast;
})();

