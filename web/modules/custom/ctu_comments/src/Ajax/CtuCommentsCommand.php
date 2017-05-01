<?php

namespace Drupal\ctu_comments\Ajax;

use Drupal\Core\Ajax\CommandInterface;

class CtuCommentsCommand implements CommandInterface {
  protected $message;

  // Constructs a ReadMessageCommand object.
  public function __construct($message) {
    $this->message = $message;
  }

  // Implements Drupal\Core\Ajax\CommandInterface:render().
  public function render() {
    return array(
      'command' => 'ctuComments',
      'subject' => $this->message->subject,
      'content' => $this->message->content,
    );
  }
}