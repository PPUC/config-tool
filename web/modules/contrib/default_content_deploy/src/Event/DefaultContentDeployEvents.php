<?php

namespace Drupal\default_content_deploy\Event;

/**
 * Defines events for the Default Content Deploy module.
 */
final class DefaultContentDeployEvents {

  /**
   * The pre save entity event name on import contents.
   */
  public const PRE_SAVE = PreSaveEntityEvent::class;

  /**
   * Alter the entity before it will be serialized.
   *
   * @Event
   */
  const PRE_SERIALIZE = PreSerializeEvent::class;

  /**
   * Alter the serialized entity data.
   *
   * @Event
   */
  const POST_SERIALIZE = PostSerializeEvent::class;
}
