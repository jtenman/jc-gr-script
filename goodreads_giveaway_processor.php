<?php
/**
 * Ways to call this file:
 * php goodreads_giveaway_processor.php
 * php goodreads_giveaway_processor.php 2
 * php goodreads_giveaway_processor.php 1 ending_soon
 */
include('gr_api.inc');

$page = isset($_GET['page']) ? (int)$_GET['page'] : (!empty($argv[1]) ? (int)$argv[1] : NULL);
$gr_tab = isset($_GET['tab']) ? (int)$_GET['tab'] : (!empty($argv[2]) ? $argv[2] : 'recently_listed');
_goodreads_debug_setup();

if (goodreads_login($gr_un, $gr_pw)) {
  goodreads_process_giveaways($page);
}
_goodreads_curl('http://www.goodreads.com/', array(), TRUE);
_goodreads_logout();

function goodreads_process_giveaways($page = NULL) {
  global $gr_tab;
  if (!$page) $page = 1;
  $baseurl = "http://www.goodreads.com/giveaway?sort={$gr_tab}&tab={$gr_tab}";
  $total_processed = $total_found = 0;
  do {
    $processed = $found = 0;
    echo "Page {$page}:";
    $output = _goodreads_curl($baseurl . '&page=' . $page);
    _goodreads_debug('giveawaypage-' . $page . '.html', $output);
    $html = str_get_html($output);
    $giveaways = $html->find('div[class=actions] a[href^=/giveaway/enter_choose_address]');
    $total = count($giveaways);
    echo $total . " giveaways\n";
    foreach ($giveaways as $giveaway) {
      $found++;
      if (_goodreads_select_address_and_process_giveaway(GOODREADS_BASE_URL . $giveaway->href)) {
        $processed++;
        echo "Processed $page-$found/$total: {$giveaway->href}\n";
      }
      else {
        echo "FAILED $page-$found/$total: {$giveaway->href}\n";
      }
    }

    $total_processed += $processed;
    $total_found += $found;
    $page++;
  } while ($processed);

  echo "$total_processed/$total_found";
  return $total_processed;
}

function _goodreads_select_address_and_process_giveaway($url) {
  $output = _goodreads_curl($url);
  _goodreads_debug('choose_address_' . basename($url) . '.html', $output . '<pre>' . $url . '</pre>');
  //_goodreads_debug('choose_address_' . basename($url) . '.html', $output);
  $html = str_get_html($output);
  $addresses = $html->find('div[id=addresses] a[href^=/giveaway/enter_choose_address]');

  if (empty($addresses)) {
    return FALSE;
  }
  $address = reset($addresses);

  $headers = array();
  $headers[CURLOPT_POST] = TRUE;
  $values = array(
    '_method' => 'post',
    'authenticity_token' => goodreads_api_get_authenticity_token($output),
  );
  $headers[CURLOPT_POSTFIELDS] = http_build_query($values);
  $headers[CURLOPT_REFERER] = $url;
  $output = _goodreads_curl(GOODREADS_BASE_URL . $address->href, $headers);
  _goodreads_debug('entry_form_' . basename($url) . '.html', $output . '<pre>' . $address->href . print_r($values, TRUE) . '</pre>');

  $html = str_get_html($output);
  $form = $html->find('form[name=entry_form]');
  $form = array_pop($form);
  if (!$form) {
    return FALSE;
    die('Could not find the entry_form form.');
  }
  // Add in the hidden values.
  foreach ($form->find('input[type=hidden]') as $input) {
    $values[$input->name] = $input->value;
  }
  $values['terms'] = 1;
  $values['commit'] = 'enter to win';
  $headers = array();
  $headers[CURLOPT_POST] = TRUE;
  $headers[CURLOPT_POSTFIELDS] = http_build_query($values);
  $headers[CURLOPT_REFERER] = GOODREADS_BASE_URL . $address->href;

  // Process actually submitting the final form.
  $output = _goodreads_curl(GOODREADS_BASE_URL . $form->action, $headers);
  _goodreads_debug('completed_' . basename($url) . '.html', $output);

  $html = str_get_html($output);
  $processed = $html->find('div[id="header_notice_container"] div[class="noticeBox"]');
  return !empty($processed);
}
