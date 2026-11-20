/**
 * @file
 * Interacciones del tema Provenza.
 */
(function (Drupal, once) {
  'use strict';

  document.documentElement.classList.add('js');
  const sinMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Encabezado: cambia de transparente a sólido al desplazarse.
  Drupal.behaviors.provenzaEncabezado = {
    attach(context) {
      once('provenza-encabezado', '[data-encabezado]', context).forEach((barra) => {
        const revisar = () => barra.classList.toggle('es-desplazado', window.scrollY > 24);
        revisar();
        window.addEventListener('scroll', revisar, { passive: true });
      });

      once('provenza-menu', '[data-abrir-menu]', context).forEach((boton) => {
        boton.addEventListener('click', () => {
          const abierto = document.body.classList.toggle('menu-abierto');
          boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        });
        document.addEventListener('keydown', (e) => {
          if (e.key === 'Escape' && document.body.classList.contains('menu-abierto')) {
            document.body.classList.remove('menu-abierto');
            boton.setAttribute('aria-expanded', 'false');
            boton.focus();
          }
        });
      });
    },
  };

  // Revelado de bloques al entrar en pantalla.
  Drupal.behaviors.provenzaRevela = {
    attach(context) {
      const elementos = once('provenza-revela', '.revela', context);
      if (!elementos.length) {
        return;
      }
      if (sinMovimiento || !('IntersectionObserver' in window)) {
        elementos.forEach((el) => el.classList.add('es-visible'));
        return;
      }
      const observador = new IntersectionObserver((entradas) => {
        entradas.forEach((entrada) => {
          if (entrada.isIntersecting) {
            entrada.target.classList.add('es-visible');
            observador.unobserve(entrada.target);
          }
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
      elementos.forEach((el) => observador.observe(el));
    },
  };

  // Línea de tiempo: avanza conforme el usuario hace scroll.
  Drupal.behaviors.provenzaLinea = {
    attach(context) {
      once('provenza-linea', '[data-linea]', context).forEach((linea) => {
        const pasos = Array.from(linea.querySelectorAll('.linea__paso'));
        const pintar = () => {
          const caja = linea.getBoundingClientRect();
          const alto = window.innerHeight;
          let avance = (alto * 0.72 - caja.top) / caja.height;
          avance = Math.max(0, Math.min(1, avance));
          if (sinMovimiento) {
            avance = 1;
          }
          linea.style.setProperty('--avance', avance.toFixed(3));
          pasos.forEach((paso, i) => {
            paso.classList.toggle('es-activo', avance >= i / (pasos.length - 1) - 0.02);
          });
        };
        pintar();
        window.addEventListener('scroll', pintar, { passive: true });
        window.addEventListener('resize', pintar);
      });
    },
  };

  // Reproductor del mensaje de bienvenida.
  Drupal.behaviors.provenzaAudio = {
    attach(context) {
      once('provenza-audio', '[data-reproductor]', context).forEach((caja) => {
        const audio = caja.querySelector('audio');
        const boton = caja.querySelector('[data-play]');
        const tiempo = caja.querySelector('[data-tiempo]');
        const barras = Array.from(caja.querySelectorAll('.reproductor__onda i'));
        const formato = (s) => {
          s = Math.max(0, Math.round(s));
          return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        };
        audio.addEventListener('loadedmetadata', () => { tiempo.textContent = formato(audio.duration); });
        boton.addEventListener('click', () => {
          if (audio.paused) {
            audio.play();
          }
          else {
            audio.pause();
          }
        });
        audio.addEventListener('play', () => {
          caja.classList.add('es-reproduciendo');
          boton.setAttribute('aria-label', 'Pausar mensaje de bienvenida');
        });
        audio.addEventListener('pause', () => {
          caja.classList.remove('es-reproduciendo');
          boton.setAttribute('aria-label', 'Reproducir mensaje de bienvenida');
        });
        audio.addEventListener('timeupdate', () => {
          const parte = audio.duration ? audio.currentTime / audio.duration : 0;
          tiempo.textContent = formato(audio.duration - audio.currentTime || 0);
          barras.forEach((b, i) => b.classList.toggle('es-pasado', i / barras.length < parte));
        });
        audio.addEventListener('ended', () => {
          barras.forEach((b) => b.classList.remove('es-pasado'));
          tiempo.textContent = formato(audio.duration);
        });
      });
    },
  };
})(Drupal, once);
