<?php

namespace Drupal\se_item\EventSubscriber;

use Drupal\se_core\Event\SeCoreEvent;
use Drupal\se_core\Event\SeCoreEvents;
use Drupal\se_stock_item\Entity\StockItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityTypeManager;

class SeItemInsert implements EventSubscriberInterface {
  /**
   * Drupal\Core\Entity\EntityTypeManager definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   */
  public function __construct(EntityTypeManager $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      SeCoreEvents::NODE_CREATED => 'onInsert',
    ];
  }

  /**
   * When an item is saved, create an associated stock item.
   *
   * @param SeCoreEvent $event
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function onInsert(SeCoreEvent $event) {
    $node = $event->getNode();
    if ($node->bundle() == 'se_item') {
      $stock_item = StockItem::create([
        'type'    => 'se_stock_item',
        'user_id' => '1',
        'name'    => $node->title->value,
        'field_si_serial'    => ['value' => ''],
        'field_si_item_ref'  => [['target_id' => $node->id()]],
        'field_si_virtual'   => [['value' => TRUE]],
        'field_si_sale_date' => [['value' => 0]],
      ]);
      $stock_item->save();
    }
  }

}