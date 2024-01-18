<?php

namespace Drupal\rozetka_xml_generator\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Url;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\commerce_product\Entity\ProductType;
use Drupal\taxonomy\Entity\Vocabulary;

class RozetkaXmlSettingsForm extends ConfigFormBase
{

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'rozetka_xml_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return ['rozetka_xml_generator.settings'];
  }

  /**
   * Build the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['#cache'] = ['max-age' => 0];

    $config = $this->config('rozetka_xml_generator.settings');

    $form['shop_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shop Name'),
      '#default_value' => $config->get('shop_name'),
      '#required' => TRUE,
    ];

    $form['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company Name'),
      '#default_value' => $config->get('company_name'),
      '#required' => TRUE,
    ];

    $vocabularies = Vocabulary::loadMultiple();
    $vocab_options = [];
    foreach ($vocabularies as $vocab) {
      $vocab_options[$vocab->id()] = $vocab->label();
    }

    $form['taxonomy_vocabulary'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Taxonomy Vocabulary for Categories'),
      '#options' => $vocab_options,
      '#default_value' => $config->get('taxonomy_vocabulary'),
    ];

    $product_types = ProductType::loadMultiple();

    $category_fields = [];
    foreach ($product_types as $product_type) {
      $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('commerce_product', $product_type->id());
      foreach ($field_definitions as $field_name => $field_definition) {
        if ($field_definition->getType() == 'entity_reference' && $field_definition->getSetting('target_type') == 'taxonomy_term') {
          $category_fields[$field_name] = $field_definition->getLabel();
        }
      }
    }

    $form['category_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Category Field for Products'),
      '#options' => $category_fields,
      '#default_value' => $config->get('category_field'),
    ];

    $form['brand_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Brand Field for Products'),
      '#options' => $category_fields,
      '#default_value' => $config->get('brand_field'),
    ];


    $form['actions']['generate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate XML File'),
      '#submit' => ['::generateXmlFile'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Custom submit handler for generating XML file.
   */
  public function generateXmlFile(array &$form, FormStateInterface $form_state)
  {
    // Генерація XML файлу
    $xml_content = $this->generateXml();

    // Створення відповіді для скачування файлу
    $response = new Response($xml_content);
    $response->headers->set('Content-Type', 'text/xml');
    $response->headers->set('Content-Disposition', 'attachment;filename="rozetka_products.xml"');

    $form_state->setResponse($response);
  }

  /**
   * Function to generate XML content.
   */
  private function generateXml() {
    $config = $this->config('rozetka_xml_generator.settings');
    $shop_name = $config->get('shop_name');
    $company_name = $config->get('company_name');
    $site_url = Url::fromRoute('<front>', [], ['absolute' => true])->toString();

    $selected_vocab = $config->get('taxonomy_vocabulary');

    $cata_terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($selected_vocab);

    $brand_field = $config->get('brand_field');
    \Drupal::logger('my_module')->notice('Поле бренда: ' . print_r($brand_field, TRUE));

    $xml_content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml_content .= "<yml_catalog date=\"" . date('Y-m-d H:i') . "\">\n";
    $xml_content .= "  <shop>\n";
    $xml_content .= "    <name>" . htmlspecialchars($shop_name) . "</name>\n";
    $xml_content .= "    <company>" . htmlspecialchars($company_name) . "</company>\n";
    $xml_content .= "    <url>" . htmlspecialchars($site_url) . "</url>\n";
    $xml_content .= "    <currencies><currency id=\"UAH\" rate=\"1\"/></currencies>\n";
    $xml_content .= "    <categories>\n";
    foreach ($cata_terms as $term) {
      $xml_content .= "      <category id=\"" . $term->tid . "\">" . htmlspecialchars($term->name) . "</category>\n";
    }
    $xml_content .= "    </categories>\n";
    $xml_content .= "    <offers>\n";

    $product_cata_vocab = $config->get('category_field');

    $products = \Drupal::entityTypeManager()->getStorage('commerce_product')->loadMultiple();

    $language_code = 'ru';

    $field_manager = \Drupal::service('entity_field.manager');

    foreach ($products as $product) {
      $product_type = $product->bundle();
      $variations = $product->getVariations();
      $field_definitions = $field_manager->getFieldDefinitions('commerce_product', $product_type);

      $characteristic_fields = filterCharacteristicFields($field_definitions);

      foreach ($variations as $variation) {
        if ($variation->hasField('price') && !$variation->get('price')->isEmpty()) {

          $brand_name = 'NoName';
          if (!empty($brand_field) && $product->hasField($brand_field) && !$product->get($brand_field)->isEmpty()) {
            
            $brand_term_id = $product->get($brand_field)->target_id;
            $brand_term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($brand_term_id);
            if ($brand_term) {
              
              $brand_name = $brand_term->getName();
            }
          }

          $price = $variation->get('price')->first()->toPrice()->getNumber();
          $formatted_price = number_format($price, 2, '.', '');

          $old_price = '';
          if ($variation->hasField('field_old_price') && !$variation->get('field_old_price')->isEmpty()) {
            $old_price_value = $variation->get('field_old_price')->first()->getValue();
            $old_price_number = $old_price_value['number'];

            if ($old_price_number > $price) {
              $old_price = number_format($old_price_number, 2, '.', '');
            }
          }

          $categoryId = '';
          if ($product->hasField($product_cata_vocab) && !$product->get($product_cata_vocab)->isEmpty()) {
            $categoryId = $product->get($product_cata_vocab)->target_id;
          }

          $article = str_pad($product->id(), 5, '0', STR_PAD_LEFT);

          $pictures = [];
          if ($product->hasField('field_foto') && !$product->get('field_foto')->isEmpty()) {
            foreach ($product->get('field_foto') as $image) {
              $image_url = file_create_url($image->entity->getFileUri());
              $pictures[] = htmlspecialchars($image_url);
            }
          }

          $name = '';
          $description = '';

          if ($product->hasTranslation($language_code)) {
            $translated_product = $product->getTranslation($language_code);

            $name = $translated_product->label();
            $name = substr($name, 0, 255);

            $description = $translated_product->get('body')->value;
            $description = substr($description, 0, 50000);
          }

          $name_ua = $product->label();
          $name_ua = substr($name_ua, 0, 255);


          $description_ua = '';
          if ($product->hasField('body') && !$product->get('body')->isEmpty()) {
            $description_ua = $product->get('body')->value;
            $description_ua = substr($description_ua, 0, 50000);
          }

          $xml_content .= "      <offer id=\"" . $product->id() . "\" available=\"true\">\n";

          $xml_content .= "        <price>" . $formatted_price . "</price>\n";
          if (!empty($old_price)) {
            $xml_content .= "        <price_old>" . $old_price . "</price_old>\n";
          }
          $xml_content .= "        <currencyId>UAH</currencyId>\n";
          $xml_content .= "        <categoryId>" . $categoryId . "</categoryId>\n";
          foreach ($pictures as $picture_url) {
            $xml_content .= "        <picture>" . $picture_url . "</picture>\n";
          }
          $xml_content .= "        <vendor>" . htmlspecialchars($brand_name) . "</vendor>\n";
          $xml_content .= "        <article>" . $article . "</article>\n";
          $xml_content .= "        <stock_quantity>100</stock_quantity>\n";
          $xml_content .= "        <name><![CDATA[" . $name . "]]></name>\n";
          $xml_content .= "        <name_ua><![CDATA[" . $name_ua . "]]></name_ua>\n";
          $xml_content .= "        <description><![CDATA[" . $description . "]]></description>\n";
          $xml_content .= "        <description_ua><![CDATA[" . $description_ua . "]]></description_ua>\n";
          foreach ($characteristic_fields as $field_name => $field_definition) {
            if ($product->hasField($field_name) && !$product->get($field_name)->isEmpty()) {
              $field_value = $product->get($field_name)->value;
              $xml_content .= "        <param name=\"" . htmlspecialchars($field_definition->getLabel()) . "\">" . htmlspecialchars($field_value) . "</param>\n";
            }
          }
          $xml_content .= "      </offer>\n";
        }
      }
    }

    $xml_content .= "    </offers>\n";
    $xml_content .= "  </shop>\n";
    $xml_content .= "</yml_catalog>";

    return $xml_content;
  }



  /**
   * Submit handler.
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $values = $form_state->getValues();
    $config = $this->config('rozetka_xml_generator.settings');

    $config->set('shop_name', $values['shop_name'])
      ->set('company_name', $values['company_name']);

    $config->save();

    $this->config('rozetka_xml_generator.settings')
      ->set('taxonomy_vocabulary', $form_state->getValue('taxonomy_vocabulary'))
      ->save();

    $this->config('rozetka_xml_generator.settings')
      ->set('category_field', $form_state->getValue('category_field'))
      ->save();

    $this->config('rozetka_xml_generator.settings')
      ->set('brand_field', $form_state->getValue('brand_field'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
