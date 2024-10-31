<?php


namespace rnpdfimporter\core\Integration\Adapters\Gravity\Loader;
use rnpdfimporter\core\Integration\Adapters\Gravity\Entry\Retriever\GravityEntryRetriever;
use rnpdfimporter\core\Loader;
use rnpdfimporter\pr\core\PRLoader;

class GravitySubLoader extends Loader
{
    public $ItemId;
    public function __construct($prefix,$basePrefix,$dbVersion,$fileVersion,$mainFile)
    {
        $this->ItemId=12;
        $this->ProcessorLoader=new GravityProcessorLoader($this);
        $this->ProcessorLoader->Initialize();
        parent::__construct($prefix,$basePrefix,$dbVersion,$fileVersion,$mainFile);

        $this->AddMenu('PDF Importer for Gravity',$this->Prefix,'administrator','','rnpdfimporter\Pages\PDFList');

        if($this->IsPR())
        {
            $this->PRLoader=new PRLoader($this);
        }
    }

    public function AddPDFLink($message,$formData)
    {
        global $RNWPImporterCreatedEntry;
        if(!isset($RNWPImporterCreatedEntry['CreatedDocuments']))
            return $message;

        if(\strpos($message,'[wpformpdflink]')===false)
            return $message;

        $links=array();
        foreach($RNWPImporterCreatedEntry['CreatedDocuments'] as $createdDocument)
        {
            $data=array(
              'entryid'=>$RNWPImporterCreatedEntry['EntryId'],
              'templateid'=>$createdDocument['TemplateId'],
              'nonce'=>\wp_create_nonce($this->Prefix.'_'.$RNWPImporterCreatedEntry['EntryId'].'_'.$createdDocument['TemplateId'])
            );
            $url=admin_url('admin-ajax.php').'?data='.\json_encode($data).'&action='.$this->Prefix.'_view_pdf';
            $links[]='<a target="_blank" href="'.esc_attr($url).'">'.\esc_html($createdDocument['Name']).'.pdf</a>';
        }

        $message=\str_replace('[wpformpdflink]',\implode($links),$message);

        return $message;


    }

    /**
     * @return GravityEntryRetriever
     */
    public function CreateEntryRetriever()
    {
        return new GravityEntryRetriever($this);
    }


    public function AddBuilderScripts()
    {
        $this->AddScript('wpformbuilder','js/dist/WPFormBuilder_bundle.js',array('jquery','wp-element','regenerator-runtime','@builder'));
    }

    public function GetPurchaseURL()
    {
        return 'https://pdfimporter.rednao.com/pdf-importer-for-gravity-get-it/';
    }


    public function AddAdvertisementParams($params)
    {
        if(\get_option($this->Prefix.'never_show_add',false)==true)
        {
            $params['Text']='';

        }else
        {
            $params['Text'] = 'Want to create a pdf instead of importing one?';
            $params['LinkText'] = 'Try PDF Builder for Gravity Forms';
            $params['LinkURL'] = 'https://pdfbuilder.rednao.com/get-it-gravity/';
            $params['Icon'] = $this->URL . 'images/adIcons/gravity.png';
        }
        return $params;
    }

    public function GetProductItemId()
    {
        return 89;
    }
}