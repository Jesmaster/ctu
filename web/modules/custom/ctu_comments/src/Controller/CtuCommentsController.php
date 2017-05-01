<?php

namespace Drupal\ctu_comments\Controller;

use Drupal\comment\CommentInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ctu_comments\Ajax\CtuCommentsCommand;

/**
 * Class CtuCommentsController.
 *
 * @package Drupal\ctu_comments\Controller
 */
class CtuCommentsController extends ControllerBase {

  public function comment_load($method, CommentInterface $comment) {
    if($method == 'ajax'){
      $response = new AjaxResponse();

      $message = new \stdClass();
      $message->subject = 'TEST';
      $message->content = '<p>Test content</p>';

      $response->addCommand(new CtuCommentsCommand($message));

      return $response;
    }
  }

}
