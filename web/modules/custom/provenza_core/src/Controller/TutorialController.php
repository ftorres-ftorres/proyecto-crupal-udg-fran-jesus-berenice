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
    return [
      'intro' => [
        '#markup' => '<div class="provenza-tutorial"><p>Este portal de colonos de Provenza Residencial está construido con <strong>Drupal</strong>, un sistema de gestión de contenidos de código abierto. Drupal organiza la información en piezas reutilizables (nodos, formularios, vistas, roles) y las combina como bloques de Lego.</p><p>Esta área explica, en español sencillo, qué módulos se usaron y dónde se ven en el sitio. No hace falta saber programar para leerla.</p>',
      ],
      'diagram' => [
        '#markup' => '<ol class="provenza-diagram"><li>Un colono llena un formulario (Webform).</li><li>Se crea un expediente con folio (nodo).</li><li>Administración cambia el estado (flujos de trabajo).</li><li>El sistema envía un correo (ECA y Symfony Mailer).</li><li>Caseta o mantenimiento ven su lista (vistas y permisos).</li></ol>',
      ],
      'nav' => $this->nav(),
      'suffix' => ['#markup' => '</div>'],
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
        '#markup' => '<a class="provenza-tile" href="/asi-lo-construimos/modulos/' . $term->id() . '"><strong>' . $term->label() . '</strong><span>' . $count . ' módulos</span></a>',
      ];
    }
    return [
      'nav' => $this->nav(),
      'cards' => [
        '#prefix' => '<div class="provenza-tiles">',
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
      'list' => ['#theme' => 'item_list', '#items' => $items, '#attributes' => ['class' => ['provenza-module-list']]],
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
    $items = [];
    foreach ($terms as $term => $def) {
      $items[] = ['#markup' => '<strong>' . $term . '.</strong> ' . $def];
    }
    return ['nav' => $this->nav(), 'list' => ['#theme' => 'item_list', '#items' => $items]];
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
          <p><a href="/asi-lo-construimos">Volver al inicio de la guía</a> · <a href="/inicio">Volver al portal</a></p>',
      ],
    ];
  }

  protected function nav(): array {
    return ['#markup' => $this->renderNav()];
  }

  protected function renderNav(): string {
    return '<nav class="provenza-tutorial-nav" aria-label="Así lo construimos"><a href="/asi-lo-construimos">Inicio de la guía</a> · <a href="/asi-lo-construimos/como-funciona-drupal">¿Cómo funciona Drupal?</a> · <a href="/asi-lo-construimos/modulos">Módulos por categoría</a> · <a href="/asi-lo-construimos/glosario">Glosario</a> · <a href="/asi-lo-construimos/creditos">Créditos</a></nav>';
  }

}
