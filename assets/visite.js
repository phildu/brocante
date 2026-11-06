// Visite virtuelle : agrandissement des photos du reportage, au clavier comme au doigt.
(function () {
  'use strict';

  var box = document.getElementById('visit-lightbox');
  if (!box) return;

  var links = Array.prototype.slice.call(document.querySelectorAll('a.visit-shot'));
  if (!links.length) return;

  var image = document.getElementById('visit-lightbox-img');
  var title = document.getElementById('visit-lightbox-title');
  var caption = document.getElementById('visit-lightbox-caption');
  var counter = document.getElementById('visit-lightbox-count');
  var closeButton = box.querySelector('[data-lb-close]');
  var prevButton = box.querySelector('[data-lb-prev]');
  var nextButton = box.querySelector('[data-lb-next]');
  var index = 0;
  var lastFocus = null;
  var previousOverflow = '';

  function paint(i) {
    var link = links[i];
    var thumb = link.querySelector('img');
    image.src = link.getAttribute('href');
    image.alt = thumb ? thumb.alt : '';
    title.textContent = link.getAttribute('data-title') || '';
    var text = link.getAttribute('data-caption') || '';
    caption.textContent = text;
    caption.hidden = text === '';
    counter.textContent = (i + 1) + ' / ' + links.length;
  }

  function open(i) {
    lastFocus = document.activeElement;
    index = i;
    paint(index);
    previousOverflow = document.body.style.overflow;
    box.hidden = false;
    document.body.style.overflow = 'hidden';
    closeButton.focus();
  }

  function close() {
    box.hidden = true;
    document.body.style.overflow = previousOverflow;
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }

  function step(direction) {
    index = (index + direction + links.length) % links.length;
    paint(index);
  }

  links.forEach(function (link, i) {
    link.addEventListener('click', function (event) {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
      event.preventDefault();
      open(i);
    });
  });

  closeButton.addEventListener('click', close);
  prevButton.addEventListener('click', function () { step(-1); });
  nextButton.addEventListener('click', function () { step(1); });
  box.addEventListener('click', function (event) {
    if (event.target === box) close();
  });

  document.addEventListener('keydown', function (event) {
    if (box.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); close(); }
    else if (event.key === 'ArrowRight') { event.preventDefault(); step(1); }
    else if (event.key === 'ArrowLeft') { event.preventDefault(); step(-1); }
  });

  box.addEventListener('keydown', function (event) {
    if (event.key !== 'Tab') return;
    var buttons = [closeButton, prevButton, nextButton];
    var first = buttons[0];
    var last = buttons[buttons.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
})();
