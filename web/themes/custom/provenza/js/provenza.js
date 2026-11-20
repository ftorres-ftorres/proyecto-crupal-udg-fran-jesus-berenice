/**
 * @file
 * Interacciones del tema Provenza.
 */
(function (Drupal, once) {
  'use strict';

  document.documentElement.classList.add('js');
  const sinMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Barra compacta: aparece cuando el marco superior sale de la vista.
  Drupal.behaviors.provenzaBarra = {
    attach(context) {
      once('provenza-barra', '.barra--flotante', context).forEach((barra) => {
        const marco = document.querySelector('.marco');
        const revisar = () => {
          const limite = marco ? marco.getBoundingClientRect().bottom : 0;
          barra.classList.toggle('es-visible', limite < 90);
        };
        revisar();
        window.addEventListener('scroll', revisar, { passive: true });
      });
    },
  };

  // Menú móvil a pantalla completa.
  Drupal.behaviors.provenzaMenu = {
    attach(context) {
      const menu = document.querySelector('[data-menu-movil]');
      if (!menu) {
        return;
      }
      const cerrar = () => {
        menu.hidden = true;
        document.body.classList.remove('menu-abierto');
        document.querySelectorAll('[data-abrir-menu]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
      };
      once('provenza-menu', '[data-abrir-menu]', context).forEach((boton) => {
        boton.addEventListener('click', () => {
          menu.hidden = false;
          document.body.classList.add('menu-abierto');
          boton.setAttribute('aria-expanded', 'true');
          menu.querySelector('a')?.focus();
        });
      });
      once('provenza-menu-cerrar', '[data-cerrar-menu]', context).forEach((b) => b.addEventListener('click', cerrar));
      once('provenza-menu-esc', 'body', context).forEach(() => {
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !menu.hidden) { cerrar(); } });
      });
    },
  };

  // Sonido del video del hero: al activarlo, el video arranca desde el inicio con la narración.
  Drupal.behaviors.provenzaSonido = {
    attach(context) {
      once('provenza-sonido', '[data-sonido]', context).forEach((boton) => {
        const video = document.querySelector('[data-video-hero]');
        if (!video) {
          boton.hidden = true;
          return;
        }
        const texto = boton.querySelector('.sonido__texto');
        let primeraVez = true;
        boton.addEventListener('click', () => {
          const activar = video.muted;
          if (activar && primeraVez) {
            video.currentTime = 0;
            primeraVez = false;
          }
          video.muted = !activar;
          video.play();
          boton.setAttribute('aria-pressed', activar ? 'true' : 'false');
          texto.textContent = activar ? 'Silenciar' : 'Escuchar el video';
        });
        if (sinMovimiento) {
          video.pause();
        }
      });
    },
  };

  // Mostrar u ocultar la contraseña en las páginas de acceso.
  Drupal.behaviors.provenzaClave = {
    attach(context) {
      once('provenza-clave', '.acceso input[type=password]', context).forEach((campo) => {
        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'ver-clave';
        boton.setAttribute('aria-label', 'Mostrar contraseña');
        boton.innerHTML = '<svg class="icono" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21.54 11.05c.3.42.46.63.46.95s-.15.53-.46.95C20.18 14.86 16.73 19 12 19s-8.18-4.14-9.54-6.05C2.15 12.53 2 12.32 2 12s.15-.53.46-.95C3.82 9.14 7.27 5 12 5s8.18 4.14 9.54 6.05Z" stroke="currentColor" stroke-width="1.5"/><path d="M15 12a3 3 0 1 0-6 0 3 3 0 0 0 6 0Z" stroke="currentColor" stroke-width="1.5"/></svg>';
        campo.insertAdjacentElement('afterend', boton);
        boton.addEventListener('click', () => {
          const ver = campo.type === 'password';
          campo.type = ver ? 'text' : 'password';
          boton.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
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
})(Drupal, once);

// Alertas de avisos urgentes: se muestran hasta que la persona las cierra.
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.provenzaAlerta = {
    attach(context) {
      once('provenza-alerta', '[data-alerta]', context).forEach((alerta) => {
        const clave = 'provenza-alerta-' + alerta.dataset.alerta;
        let cerrada = false;
        try { cerrada = window.localStorage.getItem(clave) === '1'; } catch (e) { cerrada = false; }
        alerta.hidden = cerrada;
        alerta.querySelector('[data-cerrar-alerta]').addEventListener('click', () => {
          alerta.hidden = true;
          try { window.localStorage.setItem(clave, '1'); } catch (e) { /* Sin almacenamiento: solo se oculta. */ }
        });
      });
    },
  };
})(Drupal, once);

// Galería de instalaciones: se abre el panel bajo el cursor o con el foco del teclado.
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.provenzaGaleria = {
    attach(context) {
      once('provenza-galeria', '[data-galeria]', context).forEach((galeria) => {
        const paneles = Array.from(galeria.querySelectorAll('.galeria__panel'));
        const activar = (panel) => paneles.forEach((p) => p.classList.toggle('es-activo', p === panel));
        paneles.forEach((panel) => {
          panel.addEventListener('mouseenter', () => activar(panel));
          panel.addEventListener('focus', () => activar(panel));
        });
      });
    },
  };
})(Drupal, once);
