<?php


namespace rnpdfimporter\core\Integration\Adapters\Gravity\Settings\Forms;


use Exception;
use rnpdfimporter\core\Integration\Processors\Settings\Forms\FieldSettingsFactoryBase;

class GravityFieldSettingsFactory extends FieldSettingsFactoryBase
{
    public function GetFieldByOptions($options)
    {
        $field= parent::GetFieldByOptions($options);
        if($field!=null)
            return $field;


        if($field==null)
            throw new Exception('Invalid field settings type '.$options->Type);

        $field->InitializeFromOptions($options);
        return $field;
    }


}