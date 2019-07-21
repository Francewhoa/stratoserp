<?php

namespace Drupal\se_customer\EventSubscriber;

use Drupal\Core\Entity\EntityInterface;
use Drupal\hook_event_dispatcher\Event\Entity\EntityInsertEvent;
use Drupal\hook_event_dispatcher\Event\Entity\EntityPresaveEvent;
use Drupal\hook_event_dispatcher\Event\Entity\EntityUpdateEvent;
use Drupal\hook_event_dispatcher\HookEventDispatcherInterface;
use Drupal\se_core\ErpCore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class AccountingSaveEventSubscriber.
 *
 * When an invoice or payment node is saved, adjust the customer
 * balance by the amount of the node.
 * For invoice status updates -
 *
 * @see \Drupal\se_payment\EventSubscriber\PaymentSaveEventSubscriber
 *
 * @package Drupal\se_customer\EventSubscriber
 */
class AccountingSaveEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    /** @noinspection PhpDuplicateArrayKeysInspection */
    return [
      HookEventDispatcherInterface::ENTITY_INSERT => 'accountingInsert',
      HookEventDispatcherInterface::ENTITY_UPDATE => 'accountingUpdate',
      HookEventDispatcherInterface::ENTITY_PRE_SAVE => 'accountingReduce',
    ];
  }

  /**
   * Add the total of this invoice to the amount the customer owes.
   *
   * @param \Drupal\hook_event_dispatcher\Event\Entity\EntityInsertEvent $event
   */
  public function accountingInsert(EntityInsertEvent $event) {
    $entity = $event->getEntity();
    if ($entity->getEntityTypeId() !== 'node'
      || !in_array($entity->bundle(), ['se_invoice', 'se_payment'])) {
      return;
    }
    $this->updateCustomerBalance($entity);
  }

  /**
   * Add the total of this invoice to the amount the customer owes.
   *
   * @param \Drupal\hook_event_dispatcher\Event\Entity\EntityUpdateEvent $event
   */
  public function accountingUpdate(EntityUpdateEvent $event) {
    $entity = $event->getEntity();
    if ($entity->getEntityTypeId() !== 'node'
      || !in_array($entity->bundle(), ['se_invoice', 'se_payment'])) {
      return;
    }
    $this->updateCustomerBalance($entity);
  }

  /**
   * In case the amount changes on the saving of this entity, we need
   * to reduce the current balance by the old balance first.
   *
   * @param \Drupal\hook_event_dispatcher\Event\Entity\EntityPresaveEvent $event
   */
  public function accountingReduce(EntityPresaveEvent $event) {
    $entity = $event->getEntity();
    if ($entity->getEntityTypeId() !== 'node'
      || $entity->isNew()
      || !in_array($entity->bundle(), ['se_invoice', 'se_payment'])) {
      return;
    }

    // Is this the right way?
    $this->updateCustomerBalance($entity, TRUE);
  }

  /**
   * On invoice.
   */
  private function updateCustomerBalance(EntityInterface $entity, $reduce = FALSE) {
    if (!$customer = \Drupal::service('se_customer.service')->lookupCustomer($entity)) {
      \Drupal::logger('se_customer_invoice_save')->error('No customer set for %node', ['%node' => $entity->id()]);
      return;
    }

    $bundle_field_type = 'field_' . ErpCore::ITEM_LINE_NODE_BUNDLE_MAP[$entity->bundle()];
    $amount = $entity->{$bundle_field_type . '_total'}->value;
    if ($reduce) {
      $amount *= -1;
    }

    $balance = \Drupal::service('se_customer.service')->adjustBalance($customer, $amount);
  }

}
