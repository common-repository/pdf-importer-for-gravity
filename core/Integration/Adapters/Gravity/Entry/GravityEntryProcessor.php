<?php


namespace rnpdfimporter\core\Integration\Adapters\Gravity\Entry;


use Exception;
use GFAPI;
use rnpdfimporter\core\Integration\Adapters\Gravity\Entry\Retriever\GravityEntryRetriever;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\CheckBoxEntryItem;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\ComposedEntryItem;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\DateEntryItem;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\DateTimeEntryItem;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\DropDownEntryItem;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\EntryItemBase;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\FileUploadEntryItem;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\ListEntryItem\ListEntryItem;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\SimpleTextEntryItem;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\SimpleTextWithAmountEntryItem;
use rnpdfimporter\core\Integration\Processors\Entry\EntryItems\TimeEntryItem;
use rnpdfimporter\core\Integration\Processors\Entry\EntryProcessorBase;
use rnpdfimporter\core\Integration\Processors\Entry\Retriever\EntryRetrieverBase;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\Fields\FieldSettingsBase;
use rnpdfimporter\JPDFGenerator\JPDFGenerator;
use rnpdfimporter\pr\Managers\ConditionManager\ConditionManager;

class GravityEntryProcessor extends EntryProcessorBase
{
    public function __construct($loader)
    {
        parent::__construct($loader);
        \add_filter('gform_entry_post_save',array($this,'SaveEntry'),10,2);
        \add_action('gform_pre_send_email',array($this,'AddAttachment'),10,4);
        add_action('gform_after_update_entry',array($this,'UpdateEntry'),10,4);
        \add_shortcode('bpdfimporter_download_link',array($this,'AddPDFLink'));
        add_action( 'gform_entry_detail_sidebar_middle', array($this,'AddPDFLinkEntry'), 10, 2 );
    }

    public function AddPDFLinkEntry($form,$entry)
    {
        if(!$this->Loader->IsPR())
            return;


        global $wpdb;
        $result=$wpdb->get_results($wpdb->prepare(
            "select template.id Id,template.name Name
                    from ".$this->Loader->FormConfigTable." form
                    join ".$this->Loader->PDFImporterTable." template
                    on form.id=template.form_used
                    where original_id=%s"
            ,$entry['form_id']));

        if(!current_user_can('administrator'))
            return;
        $links='';
        foreach($result as $pdfTemplate)
        {
            $data=array(
                'entryid'=>$entry['id'],
                'templateid'=>$pdfTemplate->Id,
                'use_original_entry'=>true,
                'nonce'=>\wp_create_nonce($this->Loader->Prefix.'_'.$entry['id'].'_'.$pdfTemplate->Id.'_1')
            );

            $links.= '
                <p class="wpforms-entry-star">
                    <a href="'.esc_attr(admin_url( 'admin-ajax.php' )) .'?action='.esc_attr($this->Loader->Prefix).'_public_create_pdf&entryid='.esc_attr($entry['id']).
                '&data='.esc_attr(json_encode($data)).'">
                        <span class="dashicons dashicons-pdf"></span>View '.esc_html($pdfTemplate->Name).'
                    </a>
                </p>
            ';
        }

        if($links!='')
            echo "<div class='stuffbox'><h3><span class='hndle'>PDF Importer</span></h3><div class='inside'>$links</div></div>";

    }

    public function UpdateEntry($form,$entry_id){
        $originalEntry=GFAPI::get_entry($entry_id);
        $entry=$this->SerializeEntry($originalEntry,$form);
        $this->SaveEntryToDB($form['id'],$entry["Items"],$entry_id,$originalEntry);
    }

    public function UpdateOriginalEntryId($entryId,$formData)
    {
        if(!isset($formData['fields']))
            return;
        global $RNWPImporterCreatedEntry;
        if(!isset($RNWPImporterCreatedEntry)||!isset($RNWPImporterCreatedEntry['Entry']))
            return;

        global $wpdb;
        $wpdb->update($this->Loader->RECORDS_TABLE,array(
            'original_id'=>$entryId
        ),array('id'=>$RNWPImporterCreatedEntry['EntryId']));

    }

    public function AddAttachment($emailData,$arg1,$arg2,$arg3)
    {
        global $RNWPImporterCreatedEntry;

        global $wpdb;
        $fields=$wpdb->get_var($wpdb->prepare('select fields from '.$this->Loader->FormConfigTable.' where original_id=%s',$arg3['form_id']));

        $form=\GFAPI::get_form($arg3['form_id']);
        $entry=$this->SerializeEntry($arg3,$form);

        $entryRetriever=new GravityEntryRetriever($this->Loader);
        $entryRetriever->InitializeByEntryItems($entry["Items"],$arg3,$fields,$arg3['id']);

        global $wpdb;
        $result=$wpdb->get_results($wpdb->prepare(
            "select template.id Id,attach_to_email AttachToEmail,skip_condition SkipCondition 
                    from ".$this->Loader->FormConfigTable." form
                    join ".$this->Loader->PDFImporterTable." template
                    on form.id=template.form_used
                    where original_id=%s"
            ,$arg3['form_id']));



        foreach($result as $templateSettings)
        {
            if($this->Loader->IsPR()&&isset($templateSettings->SkipCondition))
            {
                $condition=json_decode($templateSettings->SkipCondition);
                $conditionManager=new ConditionManager();
                if($conditionManager->ShouldSkip($this->Loader, $entryRetriever,$condition))
                {
                    continue;
                }
            }

            $templateSettings->AttachToEmail=\json_decode($templateSettings->AttachToEmail);

            $generator=new JPDFGenerator($this->Loader);
            $generator->LoadByTemplateId($templateSettings->Id);
            $generator->LoadEntry($entryRetriever);
            $path=$generator->SaveInTempFolder();


            if(count($templateSettings->AttachToEmail)>0&&$this->Loader->IsPR())
            {
                global $WPFormEmailBeingProcessed;
                if(isset($WPFormEmailBeingProcessed))
                {
                    $found=false;
                    foreach($templateSettings->AttachToEmail as $attachToNotification)
                    {
                        if($this->Loader->PRLoader->ShouldProcessEmail($attachToNotification,$WPFormEmailBeingProcessed))
                            $found=true;


                    }

                    if(!$found)
                        continue;
                }
            }


            $RNWPImporterCreatedEntry['CreatedDocuments'][]=array(
                'TemplateId'=>$generator->Options->Id,
                'Name'=>$generator->GetFileName()
            );
            $emailData['attachments'][]=$path;
            $_SESSION['Gravity_Generated_PDF']=array(
                'TemplateId'=>$generator->Options->Id,
                'EntryId'=>$RNWPImporterCreatedEntry['EntryId']
            );

        }

        return $emailData;

    }


    public function SaveEntry($originalEntry,$form){
        $originalId=$originalEntry['id'];
        $entry=$this->SerializeEntry($originalEntry,$form);
        $entryId='';
        if($entry!=null)
        {
            $entryId=$this->SaveEntryToDB($form['id'],$entry["Items"],$originalId,$originalEntry);
        }


        global $RNWPImporterCreatedEntry;
        $RNWPImporterCreatedEntry=array(
            'Entry'=>$entry,
            'Raw'=>$originalEntry,
            'FormId'=>$form['id'],
            'OriginalId'=>$originalId,
            'EntryId'=>$entryId
        );

        return $originalEntry;
    }

    public function SerializeEntry($entry, $form)
    {
        global $wpdb;
        $result=$wpdb->get_results($wpdb->prepare("select id Id,name Name,fields Fields from ".$this->Loader->FormConfigTable.
            " where original_id=%s",$form['id']));

        if(count($result)==0)
            return null;

        $result=$result[0];
        $formId=$result->Id;
        $fields=\json_decode($result->Fields);

        /** @var EntryItemBase $entryItems */

        $entryItems=array();




        foreach($fields as $currentField)
        {
            $fieldId=$currentField->Id;

            $found=false;
            switch ($currentField->Type)
            {
                case 'Composed':
                    $entryItems[]=(new ComposedEntryItem())->Initialize($currentField)->SetValue((object)$entry);
                    $found=true;
                    break;
                case 'Date':
                    $entryItems[]=(new DateEntryItem())->Initialize($currentField)->SetUnix(\strtotime($entry[$fieldId]))->SetValue($entry[$fieldId]);
                    $found=true;
                    break;
                case 'Time':
                    $entryItems[]=(new TimeEntryItem())->Initialize($currentField)->SetUnix(strtotime("01/01/1970 ". $entry[$fieldId]))->SetValue($entry[$fieldId]);
                    $found=true;
                    break;
                case 'FileUpload':
                case 'Signature':
                    $url=$entry[$fieldId];
                    if($currentField->SubType=='signature')
                    {
                        $url= gf_signature()->get_signatures_folder().$url;
                    }
                    $entryItems[]=(new FileUploadEntryItem())->Initialize($currentField)->SetURL($url);
                    $found=true;
                    break;
                case 'List':
                    $items=null;
                    $listEntryItem=(new ListEntryItem())->Initialize($currentField);
                    $entryItems[]=$listEntryItem;
                    switch ($currentField->SubType)
                    {
                        case 'list':
                            $items=\unserialize($entry[$fieldId]);
                            if($items!==false&&count($items)>0)
                            {
                                if(\is_array($items[0]))
                                {
                                    $row=$listEntryItem->CreateRow();
                                    foreach($items[0] as $columnId=>$value)
                                    {
                                        $row->AddColumn($columnId,$value,$value);
                                    }
                                }else
                                {
                                    $listEntryItem->AddRowWithValue('',$items[0],$items[0]);
                                }
                            }
                            break;
                        case 'post_category':
                            $value=$entry[$fieldId];
                            if(!\is_array($value))
                                $listEntryItem->AddRowWithValue('',$value,$value);
                            else{
                                $a=1;
                            }
                            break;
                        case 'post_tags':
                            $value=strval(\json_decode($entry[$fieldId]));
                            if($value==false)
                            {
                                if($entry[$fieldId]!='')
                                    $value=[$entry[$fieldId]];
                                else
                                    break;
                            }

                            foreach($value as $currentItem)
                            {
                                $listEntryItem->AddRowWithValue($currentItem,$currentItem,$currentItem);
                            }
                            break;
                        default:
                            $a=1;
                    }
                    $found=true;
                    break;


            }

            if($found)
                continue;



            switch($currentField->SubType)
            {
                case 'text':
                case 'textarea':
                case 'select':
                case 'number':
                case 'phone':
                case 'time':
                case 'website':
                case 'email':
                case 'post_title':
                case 'post_content':
                case 'quantity':
                case 'shipping':
                case 'total':
                case 'radio':

                if(!isset($entry[$fieldId]))
                        break;
                    $entryItems[]=(new SimpleTextEntryItem())->Initialize($currentField)->SetValue($entry[$fieldId]);

                    break;
                case 'multiselect':
                    if(!isset($entry[$fieldId]))
                        break;
                    $value=\json_decode($entry[$fieldId]);
                    if($value==null)
                        break;
                    $entryItems[]=(new DropDownEntryItem())->Initialize($currentField)->SetValue($value);
                    break;
                case 'checkbox':
                    $count=1;
                    $options=array();
                    while(true)
                    {
                        if(isset($entry[$fieldId.'.'.$count]))
                        {
                            $value=$entry[$fieldId.'.'.$count];
                            if(trim($value)!='')
                                $options[]=$value;
                        }else
                            break;
                        $count++;
                    }
                    $entryItems[]=(new CheckBoxEntryItem())->Initialize($currentField)->SetValue($options);
                    break;
                case 'name':
                    $firstName='';
                    $lastName='';
                    $middleName='';
                    $prefix='';
                    $suffix='';

                    if(isset($entry[$fieldId.'.2']))
                        $prefix=$entry[$fieldId.'.2'];
                    if(isset($entry[$fieldId.'.3']))
                        $firstName=$entry[$fieldId.'.3'];
                    if(isset($entry[$fieldId.'.4']))
                        $middleName=$entry[$fieldId.'.4'];
                    if(isset($entry[$fieldId.'.6']))
                        $lastName=$entry[$fieldId.'.6'];
                    if(isset($entry[$fieldId.'.8']))
                        $suffix=$entry[$fieldId.'.8'];

                    $entryItems[]=(new GravityNameEntryItem())->InitializeWithValues($currentField,$firstName,$lastName,$prefix,$middleName,$suffix);
                    break;
                case 'date':
                    if(!isset($entry[$fieldId]))
                        break;

                    $value=\GFCommon::date_display( $entry[$fieldId], $currentField->DateFormat);
                    $entryItems[]=(new GravityDateTimeEntryItem())->InitializeWithValues($currentField,$value,\strtotime($entry[$fieldId]));

                    break;
                case 'address':
                    $streetAddress1='';
                    $streetAddress2='';
                    $city='';
                    $state='';
                    $zip='';
                    $country='';

                    if(isset($entry[$fieldId.'.1']))
                        $streetAddress1=$entry[$fieldId.'.1'];
                    if(isset($entry[$fieldId.'.2']))
                        $streetAddress2=$entry[$fieldId.'.2'];
                    if(isset($entry[$fieldId.'.3']))
                        $city=$entry[$fieldId.'.3'];
                    if(isset($entry[$fieldId.'.4']))
                        $state=$entry[$fieldId.'.4'];
                    if(isset($entry[$fieldId.'.5']))
                        $zip=$entry[$fieldId.'.5'];
                    if(isset($entry[$fieldId.'.6']))
                        $country=$entry[$fieldId.'.6'];

                    $entryItems[]=(new GravityAddressEntryItem())->InitializeWithValues($currentField,$streetAddress1,$streetAddress2,
                        $city,$state,$zip,$country);
                    break;

                case 'fileupload':
                case 'post_image':
                    if(!isset($entry[$fieldId]))
                        break;
                    $entryItems[]=(new GravityFileUploadEntryItem())->InitializeWithValues($currentField,$entry[$fieldId]);
                    break;

                case 'list':
                    if(!isset($entry[$fieldId]))
                        break;

                    $value=\unserialize($entry[$fieldId]);
                    if($value!==false)
                        $entryItems[]=(new DropDownEntryItem())->Initialize($currentField)->SetValue($value);
                    break;
                case 'product':
                    if(isset($entry[$fieldId.'.1']))
                    {
                        $entryItems[] = (new SimpleTextWithAmountEntryItem())->Initialize($currentField)->SetValue($entry[$fieldId . '.1'],
                            $entry[$fieldId . '.2']);
                    }else
                    {
                        if(isset($entry[$fieldId]))
                        {
                            $values=explode('|',$entry[$fieldId]);
                            if(count($values)==2)
                                $entryItems[] = (new SimpleTextWithAmountEntryItem())->Initialize($currentField)->SetValue($values[0],$values[1]);
                        }
                    }
                    break;
                case 'option':
                    $items=array();
                    if(isset($entry[$fieldId]))
                        $items[]=$entry[$fieldId];
                    else
                    {
                        $count=1;
                        while (true)
                        {
                            if(isset($entry[$fieldId.'.'.$count]))
                            {
                                $items[]=$entry[$fieldId.'.'.$count];
                            }else{
                                break;
                            }
                            $count++;

                        }
                    }

                    $item=new DropDownEntryItem();
                    $item->Initialize($currentField);

                    foreach($items as $submittedItem)
                    {
                        $exploded=\explode('|',$submittedItem);
                        if(count($exploded)!=2)
                            continue;

                        $item->AddItem($exploded[0],$exploded[1]);
                    }

                    $entryItems[]=$item;


            }
        }


        return array(
            "Items"=>$entryItems,
            "FormId"=>$formId
        );

    }


    public function InflateEntryItem(FieldSettingsBase $field, $entryData)
    {
        $entryItem=null;
        switch ($field->Type)
        {
            case 'Composed':
                $entryItem=(new ComposedEntryItem());
                break;
            case 'Date':
                $entryItem=(new DateEntryItem());
                break;
            case 'Time':
                $entryItem=(new TimeEntryItem());
                break;
            case 'DateTime':
                $entryItem=(new DateTimeEntryItem());
                break;
            case 'FileUpload':
                $entryItem=(new FileUploadEntryItem());
                break;
            case 'List':
                $entryItem=(new ListEntryItem());
                break;


        }
        if($entryItem==null)
        {
            switch ($field->SubType)
            {
                case 'text':
                case 'textarea':
                case 'select':
                case 'number':
                case 'phone':
                case 'time':
                case 'website':
                case 'email':
                case 'post_title':
                case 'post_content':
                case 'quantity':
                case 'shipping':
                case 'total':
                case 'radio':
                    $entryItem = new SimpleTextEntryItem();
                    break;
                case 'multiselect':
                    $entryItem = new DropDownEntryItem();
                    break;
                case 'checkbox':
                    $entryItem = new CheckBoxEntryItem();
                    break;
                case 'name':
                    $entryItem = new GravityNameEntryItem();
                    break;
                case 'date':
                case 'date-time':
                    $entryItem = new GravityDateTimeEntryItem();

                    break;
                case 'address':
                    $entryItem = new GravityAddressEntryItem();
                    break;

                case 'fileupload':
                case 'post_image':
                    $entryItem = new GravityFileUploadEntryItem();
                    break;

                case 'list':
                    $entryItem = new DropDownEntryItem();
                    break;
                case 'product':
                    $entryItem = new SimpleTextWithAmountEntryItem();
                    break;
                case 'option':
                    $entryItem = new DropDownEntryItem();
            }
        }

        if($entryItem==null)
            throw new Exception("Invalid entry sub type ".$field->SubType);
        $entryItem->InitializeWithOptions($field,$entryData);
        return $entryItem;
    }
}