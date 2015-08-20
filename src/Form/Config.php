<?php
/**
 * @file
 * Contains \Drupal\amazons3\Form\Config.
 */

namespace Drupal\amazons3\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for a site's Amazon S3 settings.
 */
class Config extends ConfigFormBase {
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'amazons3_config';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['amazons3_key'] = array(
      '#type' => 'textfield',
      '#title' => t('Amazon S3 API Key'),
      '#default_value' => $this->config('amazons3.settings')->get('amazons3_key'),
      '#required' => TRUE,
    );

    $form['amazons3_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Amazon S3 API Secret'),
      '#default_value' => $this->config('amazons3.settings')->get('amazons3_secret'),
      '#required' => TRUE,
    );

    $form['amazons3_bucket'] = array(
      '#type' => 'textfield',
      '#title' => t('Default Bucket Name'),
      '#default_value' => $this->config('amazons3.settings')->get('amazons3_bucket'),
      '#required' => TRUE,
      '#element_validate' => array('amazons3_form_bucket_validate'),
    );

    $form['amazons3_cache'] = array(
      '#type' => 'checkbox',
      '#title' => t('Enable metadata caching'),
      '#description' => t('Enable a local file metadata cache to reduce calls to S3.'),
      '#description_display' => 'before',
      '#default_value' => $this->config('amazons3.settings')->get('amazons3_cache'),
    );

    $expiration = $this->config('amazons3.settings')->get('amazons3_cache_expiration');

    $formatter = \Drupal::service('date.formatter');
    $period = array(0, 60, 180, 300, 600, 900, 1800, 2700, 3600, 10800, 21600, 32400, 43200, 86400);
    $period = array_map(array($formatter, 'formatInterval'), array_combine($period, $period));
    $period[0] = '<' . t('none') . '>';
    $form['amazons3_cache_expiration'] = array(
      '#type' => 'select',
      '#title' => t('Expiration of cached file metadata'),
      '#default_value' => $expiration,
      '#options' => $period,
      '#description' => t('The maximum time Amazon S3 file metadata will be cached. If multiple API clients are interacting with the same S3 buckets, this setting might need to be reduced or disabled.'),
      '#description_display' => 'before',
      '#states' => array(
        'visible' => array(
          ':input[name="amazons3_cache"]' => array('checked' => TRUE),
        ),
      ),
    );

    $form['amazons3_cname'] = array(
      '#type' => 'checkbox',
      '#title' => t('Enable CNAME'),
      '#description' => t('Serve files from a custom domain by using an appropriately named bucket e.g. "mybucket.mydomain.com"'),
      '#description_display' => 'before',
      '#default_value' => $this->config('amazons3.settings')->get('amazons3_cname'),
    );

    $form['amazons3_domain'] = array(
      '#type' => 'textfield',
      '#title' => t('CDN Domain Name'),
      '#description' => t('If serving files from CloudFront then the bucket name can differ from the domain name.'),
      '#description_display' => 'before',
      '#default_value' => $this->config('amazons3.settings')->get('amazons3_domain'),
      '#states' => array(
        'visible' => array(
          ':input[id=edit-amazons3-cname]' => array('checked' => TRUE),
        ),
      ),
    );

    $form['amazons3_cloudfront'] = array(
      '#type' => 'checkbox',
      '#title' => t('Enable CloudFront'),
      '#description' => t('Deliver URLs through a CloudFront domain when using presigned URLs. This requires additional settings.php configuation. See README.md for details. Note that CloudFront URLs do not support other configuration options like Force Save As or Torrents, but they do support presigned URLs.'),
      '#description_display' => 'before',
      '#default_value' => $this->config('amazons3.settings')->get('amazons3_cloudfront'),
      '#states' => array(
        'visible' => array(
          ':input[id=edit-amazons3-cname]' => array('checked' => TRUE),
        ),
      ),
    );

    $form['amazons3_hostname'] = array(
      '#type' => 'textfield',
      '#title' => t('Custom Hostname'),
      '#description' => t('For use with an alternative API compatible service e.g. <a href="@cloud">Google Cloud Storage</a>', array('@cloud' => 'https://cloud.google.com/storage‎')),
      '#description_display' => 'before',
      '#default_value' => $this->config('amazons3.settings')->get('amazons3_hostname'),
    );

    $form['amazons3_torrents'] = array(
      '#type' => 'textarea',
      '#title' => t('Torrents'),
      '#description' => t('A list of paths that should be delivered through a torrent url. Enter one value per line e.g. "mydir/.*". Paths are relative to the Drupal file directory and use patterns as per <a href="@preg_match">preg_match</a>. This won\'t work for CloudFront presigned URLs.', array('@preg_match' => 'http://php.net/preg_match')),
      '#description_display' => 'before',
      '#default_value' => $this->implode('amazons3_torrents'),
      '#rows' => 10,
    );

    $form['amazons3_presigned_urls'] = array(
      '#type' => 'textarea',
      '#title' => t('Presigned URLs'),
      '#description' => t('A list of timeouts and paths that should be delivered through a presigned url. Enter one value per line, in the format &lt;timeout&gt;|&lt;path&gt; e.g. "60|mydir/.*". Paths are relative to the Drupal file directory and use patterns as per <a href="@preg_match">preg_match</a>.', array('@preg_match' => 'http://php.net/preg_match')),
      '#description_display' => 'before',
      '#default_value' => $this->implode('amazons3_presigned_urls'),
      '#rows' => 10,
    );

    $form['amazons3_saveas'] = array(
      '#type' => 'textarea',
      '#title' => t('Force Save As'),
      '#description' => t('A list of paths that force the user to save the file by using Content-disposition header. Prevents autoplay of media. Enter one value per line. e.g. "mydir/.*". Paths are relative to the Drupal file directory and use patterns as per <a href="@preg_match">preg_match</a>. Files must use a presigned url to use this, however it won\'t work for CloudFront presigned URLs and you\'ll need to set the content-disposition header in the file metadata before saving.', array('@preg_match' => 'http://php.net/preg_match')),
      '#description_display' => 'before',
      '#default_value' => $this->implode('amazons3_saveas'),
      '#rows' => 10,
    );

    $form['amazons3_rrs'] = array(
      '#type' => 'textarea',
      '#title' => t('Reduced Redundancy Storage'),
      '#description' => t('A list of paths that save the file in <a href="@rrs">Reduced Redundancy Storage</a>. Enter one value per line. e.g. "styles/.*". Paths are relative to the Drupal file directory and use patterns as per <a href="@preg_match">preg_match</a>.', array('@rrs' => 'http://aws.amazon.com/s3/faqs/#rrs_anchor', '@preg_match' => 'http://php.net/preg_match')),
      '#description_display' => 'before',
      '#default_value' => $this->implode('amazons3_rrs'),
      '#rows' => 10,
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // If cloudfront is enabled, make sure that a key pair and cert have been set.
    $cloudfront = $form_state->getValue('amazons3_cloudfront');
    if ($cloudfront) {
      $keypair = $this->config('amazons3.settings')->get('amazons3_cloudfront_keypair_id');
      $pem = $this->config('amazons3.settings')->get('amazons3_cloudfront_private_key');
      if (empty($keypair) || empty($pem)) {
        $form_state->setErrorByName('amazons3_cloudfront', t('You must configure your CloudFront credentials in settings.php.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('amazons3.settings')
      ->set('amazons3_key', $form_state->getValue('amazons3_key'))
      ->set('amazons3_secret', $form_state->getValue('amazons3_secret'))
      ->set('amazons3_bucket', $form_state->getValue('amazons3_bucket'))
      ->set('amazons3_cache', $form_state->getValue('amazons3_cache'))
      ->set('amazons3_cache_expiration', $form_state->getValue('amazons3_cache_expiration'))
      ->set('amazons3_cname', $form_state->getValue('amazons3_cname'))
      ->set('amazons3_domain', $form_state->getValue('amazons3_domain'))
      ->set('amazons3_cloudfront', $form_state->getValue('amazons3_cloudfront'))
      ->set('amazons3_cloudfront', $form_state->getValue('amazons3_hostname'))
      ->set('amazons3_rrs', $this->explode('amazons3_rrs', $form_state))
      ->set('amazons3_saveas', $this->explode('amazons3_saveas', $form_state))
      ->set('amazons3_torrents', $this->explode('amazons3_torrents', $form_state))
      ->set('amazons3_presigned_urls', $this->explode('amazons3_presigned_urls', $form_state))
      ->save();

    return parent::submitForm($form, $form_state);
  }

  /**
   * Explode newlines in our admin form into arrays to save.
   *
   * @param string $name
   *   Name of the element to explode the value from.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   Exploded and properly formatted value of the element.
   */
  protected function explode($name, FormStateInterface &$form_state) {
    $value = $form_state->getValue($name);
    if (empty($value)) {
      return array();
    }

    $value = array_map('trim', explode("\n", $value));
    $value = array_filter($value, 'strlen');

    if ($name == 'amazons3_presigned_urls') {
      $presigned_urls = array();
      foreach ($value as $line) {
        list($timeout, $pattern) = explode('|', $line);
        $presigned_urls[] = array('timeout' => $timeout, 'pattern' => $pattern);
      }

      $value = $presigned_urls;
    }

    return $value;
  }

  /**
   * Load a variable array and implode it into a string.
   *
   * @param string $name
   *   The variable to load.
   *
   * @return string
   *   The imploded string.
   */
  protected function implode($name) {
    $value = $this->config('amazons3.settings')->get($name) ?: array();
    if ($name == 'amazons3_presigned_urls') {
      $lines = array();
      foreach ($value as $config) {
        $lines[] = $config['timeout'] . '|' . $config['pattern'];
      }

      return implode("\n", $lines);
    }

    return implode("\n", $value);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return array('amazons3.settings');
  }
}
