<?php


namespace rnpdfimporter\core\Integration\Adapters\Gravity\Loader;


use rnpdfimporter\core\Integration\Adapters\Gravity\Entry\GravityEntryProcessor;
use rnpdfimporter\core\Integration\Adapters\Gravity\FormProcessor\GravityFormProcessor;
use rnpdfimporter\core\Integration\Processors\Loader\ProcessorLoaderBase;

class GravityProcessorLoader extends ProcessorLoaderBase
{

    public function Initialize()
    {

        $this->FormProcessor=new GravityFormProcessor($this->Loader);
        $this->EntryProcessor=new GravityEntryProcessor($this->Loader);
    }
}