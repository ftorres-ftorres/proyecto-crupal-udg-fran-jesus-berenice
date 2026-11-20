<?php

namespace Drupal\provenza_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\TermInterface;

/**
 * Footer-only tutorial area: Así lo construimos.
 */
class TutorialController extends ControllerBase {

  /**
   * Landing.
   */
  public function landing(): array {
    $pasos = [
      ['formulario', 'Formulario', 'Un colono llena un formulario', 'Webform'],
      ['expediente', 'Expediente', 'Se crea un expediente con folio', 'Nodo y Token'],
      ['estado', 'Estado', 'Administración cambia el estado', 'Workflows y Content Moderation'],
      ['correo', 'Correo', 'El sistema avisa por correo', 'ECA y Symfony Mailer'],
      ['lista', 'Lista', 'Caseta o mantenimiento ven su lista', 'Views y permisos'],
    ];
    $diagrama = '<ol class="diagrama" aria-label="Cómo viaja un trámite por el sitio">';
    foreach ($pasos as $i => [$icono, $titulo, $texto, $modulo]) {
      $diagrama .= '<li class="diagrama__paso" style="--i: ' . $i . '"><span class="diagrama__icono diagrama__icono--' . $icono . '" aria-hidden="true"></span><strong>' . $titulo . '</strong><span>' . $texto . '</span><em>' . $modulo . '</em></li>';
    }
    $diagrama .= '</ol>';
    $total = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)->condition('type', 'modulo_documentado')->count()->execute();
    $secciones = [
      ['/asi-lo-construimos/como-funciona-drupal', '¿Cómo funciona Drupal?', 'Núcleo, módulos, tipos de contenido, roles, vistas y bloques explicados sin tecnicismos.'],
      ['/asi-lo-construimos/modulos', 'Módulos por categoría', 'Las ' . $total . ' fichas de módulos: qué hacen, dónde se ven y cómo funcionan.'],
      ['/asi-lo-construimos/glosario', 'Glosario', 'Módulo, nodo, vista, rol, folio y otras palabras de la guía.'],
    ];
    $cards = '<div class="guia-tarjetas">';
    foreach ($secciones as [$url, $titulo, $texto]) {
      $cards .= '<a class="guia-tarjeta" href="' . $url . '"><strong>' . $titulo . '</strong><span>' . $texto . '</span></a>';
    }
    $cards .= '</div>';
    return [
      'nav' => $this->nav(),
      'intro' => [
        '#markup' => '<div class="provenza-tutorial"><p class="guia-lead">Este portal está construido con <strong>Drupal</strong>, un sistema de gestión de contenidos de código abierto. Drupal organiza la información en piezas reutilizables y las combina como bloques de Lego.</p><p>Esta área explica, en español sencillo, qué módulos se usaron y dónde se ven en el sitio. No hace falta saber programar para leerla.</p><h2>Cómo viaja un trámite</h2>',
      ],
      'diagram' => ['#markup' => $diagrama],
      'cards' => ['#markup' => '<h2>Explora la guía</h2>' . $cards . '</div>'],
    ];
  }
  /**
   * How Drupal works.
   */
  public function howDrupalWorks(): array {
    return [
      '#markup' => '<div class="provenza-tutorial">' . $this->renderNav() . '
        <h2>Módulos del núcleo y contribuidos</h2>
        <p>El <strong>núcleo</strong> es lo que Drupal trae de fábrica: páginas, usuarios, menús, vistas. Los <strong>módulos contribuidos</strong> los publica la comunidad (por ejemplo Webform o Gin) y se instalan además del núcleo.</p>
        <h2>Tipos de contenido</h2>
        <p>Un tipo de contenido es una plantilla. En este sitio hay plantillas para avisos, solicitudes, domicilios, instalaciones y las fichas de esta guía.</p>
        <h2>Roles</h2>
        <p>Un rol es un conjunto de permisos. Aquí existen visitante, residente titular, familiar o inquilino, caseta, mantenimiento, administración, mesa directiva y webmaster.</p>
        <h2>Vistas</h2>
        <p>Una vista es una lista armada con filtros: “mis solicitudes”, “cuotas y tarifas” o “visitas de hoy”.</p>
        <h2>Bloques</h2>
        <p>Los bloques son piezas que se colocan en regiones de la página: el menú, el aviso urgente o las acciones rápidas del inicio.</p>
      </div>',
    ];
  }

  /**
   * Category cards.
   */
  public function categories(): array {
    $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'categorias_modulo']);
    $cards = [];
    foreach ($terms as $term) {
      $count = $this->entityTypeManager()->getStorage('node')->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'modulo_documentado')
        ->condition('field_categoria_modulo', $term->id())
        ->count()
        ->execute();
      $cards[] = [
        '#markup' => '<a class="guia-tarjeta guia-tarjeta--categoria" href="/asi-lo-construimos/modulos/' . $term->id() . '"><span class="diagrama__icono diagrama__icono--cat-' . \Drupal::service('transliteration')->transliterate(mb_strtolower(strtok($term->label(), ' ')), 'es') . '" aria-hidden="true"></span><strong>' . $term->label() . '</strong><span>' . $count . ' módulos documentados</span></a>',
      ];
    }
    return [
      'nav' => $this->nav(),
      'cards' => [
        '#prefix' => '<div class="guia-tarjetas">',
        '#suffix' => '</div>',
        'items' => $cards,
      ],
    ];
  }

  /**
   * Modules in a category.
   */
  public function category(TermInterface $tid): array {
    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'modulo_documentado')
      ->condition('field_categoria_modulo', $tid->id())
      ->sort('title')
      ->execute();
    $items = [];
    foreach (Node::loadMultiple($nids) as $node) {
      $image = '';
      if ($node->hasField('field_captura') && !$node->get('field_captura')->isEmpty() && $node->get('field_captura')->entity) {
        $image = \Drupal::service('file_url_generator')->generateString($node->get('field_captura')->entity->getFileUri());
      }
      $items[] = [
        '#theme' => 'provenza_module_card',
        '#title' => $node->label(),
        '#summary' => $node->get('field_resumen')->value,
        '#url' => $node->toUrl()->toString(),
        '#category' => $tid->label(),
        '#image' => $image,
      ];
    }
    return [
      'nav' => $this->nav(),
      'list' => ['#prefix' => '<div class="guia-modulos">', '#suffix' => '</div>', 'items' => $items],
    ];
  }

  /**
   * Category title callback.
   */
  public function categoryTitle(TermInterface $tid): string {
    return $tid->label();
  }

  /**
   * Glossary.
   */
  public function glossary(): array {
    $terms = [
      'Módulo' => 'Pieza de software que añade una función a Drupal.',
      'Nodo' => 'Un contenido: un aviso, una solicitud, una instalación.',
      'Vista' => 'Lista filtrada de contenidos.',
      'Rol' => 'Conjunto de permisos (por ejemplo Administración).',
      'Flujo de trabajo' => 'Estados por los que pasa un expediente (Recibida, En proceso, Resuelta).',
      'Webform' => 'Constructor de formularios avanzados.',
      'Token' => 'Marcador de posición que se reemplaza al enviar un correo, como el folio.',
      'Folio' => 'Identificador único de seguimiento, por ejemplo SOL-2026-0001.',
      'Caseta' => 'Puesto de control de acceso del condominio.',
      'Colono' => 'Residente o titular de un domicilio en Provenza Residencial.',
    ];
    $html = '<dl class="glosario">';
    foreach ($terms as $term => $def) {
      $html .= '<div><dt>' . $term . '</dt><dd>' . $def . '</dd></div>';
    }
    $html .= '</dl>';
    return ['nav' => $this->nav(), 'list' => ['#markup' => $html]];
  }

  /**
   * Credits.
   */
  public function credits(): array {
    return [
      'nav' => $this->nav(),
      'body' => [
        '#markup' => '<p>Este sitio usa Drupal 11 y módulos de la comunidad de Drupal. Gracias a las personas que mantienen Webform, ECA, Gin, Pathauto y el resto de proyectos listados en esta guía.</p>
          <p>Última actualización de esta prueba de concepto: 19 de noviembre de 2026. Zona horaria: America/Mexico_City.</p>
          <p><strong>reCAPTCHA v3</strong> está activo en registro, contacto y quejas (junto con Honeypot). En local se usan las claves de prueba de Google para que los formularios se puedan enviar; en producción se sustituyen por las del dominio autenticado. Un correo SMTP de dominio autenticado tampoco está configurado: los correos se capturan en Mailpit de DDEV.</p>
          <h2>Multimedia del sitio</h2>
          <ul>
            <li><strong>Fotografías.</strong> Imágenes de Pexels con licencia libre, recortadas y corregidas de color por el equipo (contraste, saturación, viñeta y enfoque) y exportadas a WebP. Son ilustrativas: no muestran las instalaciones reales de Provenza.</li>
            <li><strong>Mensaje de bienvenida.</strong> Narración generada con voz sintética en español de México y mezclada con una base musical original compuesta con ffmpeg: entrada, atenuación automática bajo la voz y normalización a -16 LUFS.</li>
            <li><strong>Animaciones.</strong> Entradas al hacer scroll, línea de tiempo de solicitudes, ondas del reproductor y este diagrama, hechas con CSS y JavaScript. Respetan la preferencia de movimiento reducido del sistema.</li>
            <li><strong>Iconos.</strong> Hugeicons, colección gratuita.</li>
            <li><strong>Tipografías.</strong> Instrument Serif y Onest, de Google Fonts.</li>
          </ul>
          <p><a href="/asi-lo-construimos">Volver al inicio de la guía</a> <a href="/inicio">Volver al portal</a></p>',
      ],
    ];
  }

  protected function nav(): array {
    return ['#markup' => $this->renderNav()];
  }

  protected function renderNav(): string {
    return '<nav class="provenza-tutorial-nav" aria-label="Así lo construimos"><a href="/asi-lo-construimos">Inicio de la guía</a> <a href="/asi-lo-construimos/como-funciona-drupal">¿Cómo funciona Drupal?</a> <a href="/asi-lo-construimos/modulos">Módulos por categoría</a> <a href="/asi-lo-construimos/glosario">Glosario</a> <a href="/asi-lo-construimos/creditos">Créditos</a></nav>';
  }

}
