<?php


namespace rnpdfimporter\core\Integration\Adapters\Gravity\FormProcessor;


use rnpdfimporter\core\Integration\Processors\FormProcessor\FormProcessorBase;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\EmailNotification;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\ComposedFieldItem;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\ComposedFieldSettings;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\DateFieldSettings;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\FieldSettingsBase;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\FileUploadFieldSettings;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\ListFieldSettings\ListFieldSettings;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\MultipleOptionsFieldSettings;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\NumberFieldSettings;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\TextFieldSettings;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\TimeFieldSettings;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\FormSettings;

class GravityFormProcessor extends FormProcessorBase
{
    public function __construct($loader)
    {
        parent::__construct($loader);
        \add_action('gform_after_save_form',array($this,'SavingForm'),10,3);
    }

    public function SavingForm($formMeta,$arg2,$arg3){
        $formSettings=new FormSettings();
        $formSettings->OriginalId=$formMeta['id'];
        $formSettings->Name=$formMeta['title'];

        foreach($formMeta['notifications'] as $currentNotification)
        {
            if($currentNotification['toType']!='email')
                continue;

            $formSettings->EmailNotifications[]=new EmailNotification($currentNotification['id'],$currentNotification['name']);

        }

        $formSettings->Fields=$this->SerializeFields($formMeta['fields']);
        $this->SaveOrUpdateForm($formSettings);
    }

    public function SerializeForm($form){
        $formId=$form['form_id'];
        $meta=\json_decode($form['display_meta']);
        $notifications=\json_decode($form['notifications'],true);
        $fields=$meta->fields;

        $formSettings=new FormSettings();

        foreach($notifications as $currentNotification)
        {
            if($currentNotification['toType']!='email'&&$currentNotification['toType']!='field')
                continue;

            $formSettings->EmailNotifications[]=new EmailNotification($currentNotification['id'],$currentNotification['name']);

        }

        $formSettings->OriginalId=$formId;
        $formSettings->Name=$meta->title;
        $formSettings->Fields=$this->SerializeFields($fields);


        return $formSettings;
    }

    public function SerializeFields($fieldList)
    {
        /** @var FieldSettingsBase[] $fieldSettings */
        $fieldSettings=array();
        $fieldList=\json_decode(\json_encode($fieldList));
        foreach($fieldList as $field)
        {
            switch($field->type)
            {
                case 'text':
                case 'textarea':
                case 'phone':
                case 'website':
                case 'email':
                case 'post_title':
                case 'post_content':
                case 'post_excerpt':
                    $fieldSettings[]=(new TextFieldSettings())->Initialize($field->id,$field->label,$field->type);
                    break;
                case 'time':
                    $fieldSettings[]=(new TimeFieldSettings())->Initialize($field->id,$field->label,$field->type);
                    break;
                case 'select':
                case 'multiselect':
                case 'checkbox':
                case 'radio':
                case 'option':
                    $settings=(new MultipleOptionsFieldSettings())->Initialize($field->id,$field->label,$field->type);
                    foreach($field->choices as $choice)
                    {
                        if(!\is_object($choice))
                            $choice=(object)$choice;
                        $settings->AddOption($choice->text,$choice->value,$choice->price);
                    }
                    $fieldSettings[]=$settings;
                    break;
                case 'number':
                case 'product':
                case 'quantity':
                case 'shipping':
                case 'total':
                    $fieldSettings[]=(new NumberFieldSettings())->Initialize($field->id,$field->label,$field->type);
                    break;
                case 'name':
                    $nameSettings=(new ComposedFieldSettings())->Initialize($field->id,$field->label,$field->type);
                    foreach($field->inputs as $input)
                    {
                        $nameSettings->AddItem($input->id,$input->id,$input->label);
                    }

                    $fieldSettings[]=$nameSettings;
                    break;
                case 'address':
                    $nameSettings=(new ComposedFieldSettings())->Initialize($field->id,$field->label,$field->type);
                    foreach($field->inputs as $input)
                    {
                        $nameSettings->AddComposedFieldItem((new ComposedFieldItem($input->id,$input->id,$input->label))->AddCommaBefore() );
                    }

                    $fieldSettings[]=$nameSettings;
                    break;
                case 'date':
                    $fieldSettings[]=(new DateFieldSettings())->Initialize($field->id,$field->label,$field->type);
                    break;
                case 'list':
                case 'post_tags':
                case "post_category":
                    $listSettings=(new ListFieldSettings())->Initialize($field->id,$field->label,$field->type);
                    if(isset($field->choices)&&\is_array($field->choices))
                    {
                        foreach($field->choices as $column)
                        {
                            $listSettings->AddColumn($column->text,$column->value);
                        }

                    }
                    $fieldSettings[]=$listSettings;
                    break;
                case "fileupload":
                case 'post_image':
                case 'post_custom_field':
                case 'signature':
                    $fieldSettings[]=(new FileUploadFieldSettings())->Initialize($field->id,$field->label,$field->type);
                    break;

            }
        }

        return $fieldSettings;
    }

    public function SyncCurrentForms()
    {
        global $wpdb;
        $results=$wpdb->get_results("select form_id, display_meta,notifications from ".$wpdb->prefix."gf_form_meta",'ARRAY_A');
        $formIds=array();
        foreach($results as $form)
        {
            $formIds[]=$form['form_id'];
            $form=$this->SerializeForm($form);
            $this->SaveOrUpdateForm($form);
        }

        $how_many = count($formIds);
        $placeholders = array_fill(0, $how_many, '%d');
        $format = implode(', ', $placeholders);

        $query = "delete from ".$this->Loader->FormConfigTable." where original_id not in($format)";
        $wpdb->query($wpdb->prepare($query,$formIds));

    }

    public function GetFormList()
    {
        global $wpdb;

        return $wpdb->get_results("select id Id, name Name, fields Fields,original_id OriginalId,notifications Notifications from ".$this->Loader->FormConfigTable );
    }

}