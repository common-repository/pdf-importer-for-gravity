<?php


namespace rnpdfimporter\core\Integration\Adapters\Gravity\Entry\Retriever;


use rnpdfimporter\core\Integration\Adapters\Gravity\Entry\GravityEntryProcessor;
use rnpdfimporter\core\Integration\Adapters\Gravity\Settings\Forms\GravityFieldSettingsFactory;
use rnpdfimporter\core\Integration\Processors\Entry\EntryProcessorBase;
use rnpdfimporter\core\Integration\Processors\Entry\Retriever\EntryRetrieverBase;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\FieldSettingsFactoryBase;

class GravityEntryRetriever extends EntryRetrieverBase
{
    /**
     * @return FieldSettingsFactoryBase
     */
    public function GetFieldSettingsFactory()
    {
        return new GravityFieldSettingsFactory();
    }

    /**
     * @return EntryProcessorBase
     */
    protected function GetEntryProcessor()
    {
        return new GravityEntryProcessor($this->Loader);
    }

    public function GetProductItems()
    {
        return array();
    }
}