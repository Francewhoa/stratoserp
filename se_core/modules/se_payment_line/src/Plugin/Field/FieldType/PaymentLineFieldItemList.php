<?php

namespace Drupal\se_payment_line\Plugin\Field\FieldType;

use Drupal\Core\Field\EntityReferenceFieldItemList;

/**
 * Represents a configurable entity se_item_line field.
 */
class PaymentLineFieldItemList extends EntityReferenceFieldItemList {

  /**
   * {@inheritdoc}
   */
  public function referencedEntities() {
    // TODO: Change the autogenerated stub.
    return parent::referencedEntities();
  }

}
