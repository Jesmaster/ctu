(function($, Drupal) {
    Drupal.AjaxCommands.prototype.ctuComments = function(ajax, response, status){
        $('#current-msg h2').html(response.subject);
        $('#current-msg p').html(response.content);
    }
})(jQuery, Drupal);