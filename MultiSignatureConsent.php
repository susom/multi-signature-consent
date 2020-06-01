<?php
namespace Stanford\MultiSignatureConsent;

require_once "emLoggerTrait.php";

class MultiSignatureConsent extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $evalLogic;
    public $destinationFileField;
    public $inputForms = [];
    public $header;
    public $footer;
    public $saveToFileRepo;

    private static $MAKING_PDF = false;

    private static $KEEP_RECORD_ID_FIELD = false;
    private static $KEEP_PAGE_BREAKS = false;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}


	public function initialize() {
        $this->evalLogic            = $this->getProjectSetting('eval-logic');
        $this->destinationFileField = $this->getProjectSetting('destination-file-field');
        $this->header               = $this->getProjectSetting('header');
        $this->footer               = $this->getProjectSetting('footer');
        $this->saveToFileRepo       = $this->getProjectSetting('save-to-file-repo');
        $this::$KEEP_PAGE_BREAKS    = $this->getProjectSetting('keep-page-breaks');
        $this::$KEEP_RECORD_ID_FIELD= $this->getProjectSetting('keep-record-id-field');

        $instances = $this->framework->getSubSettings('instance');
        foreach ($instances as $instance) {
            $this->inputForms[] = $instance['form-name'];
        }
        // $this->emDebug($instances, $this->inputForms);
    }


	public function redcap_pdf ($project_id, $metadata, $data, $instrument = NULL, $record = NULL, $event_id = NULL, $instance = 1 ) {
        if (self::$MAKING_PDF) {


            // We were called from inside of this EM
            $this->emDebug("In PDF Hook!", func_get_args(), $this->inputForms, $this->evalLogic);

            // Build metadata from all forms
            global $Proj;

            // Get fields in all forms
            $new_meta = [];
            $fields = [];
            foreach ($Proj->metadata as $field_name => $field_meta) {
                if (in_array($field_meta['form_name'], $this->inputForms)) {
                    // This field is in our form


                    // Skip form_complete fields
                    if ($field_meta['form_name'] . "_complete" == $field_meta['field_name']) {
                        continue;
                    }

                    // Skip @HIDDEN-PDF fields
                    if (strpos($field_meta['misc'], '@HIDDEN-PDF') !== FALSE) {
                        continue;
                    }

                    // Skip record id field unless told otherwise
                    if (! $this::$KEEP_RECORD_ID_FIELD &&
                        $field_meta['field_order'] == 1
                    ) {
                        continue;
                    }

                    // In order to get all signatures on 'same page' of PDF
                    // I make it appear as all fields are on the first form
                    if (! $this::$KEEP_PAGE_BREAKS &&
                        $field_meta['form_name'] !== $instrument
                    ) {
                        $field_meta['form_name'] = $instrument;
                    }

                    // This is not a hidden PDF field
                    $new_meta[] = $field_meta;
                    $fields[] = $field_name;
                }
            }

            // Get the updated data
            $new_data = \REDCap::getData('array', $record, $fields, $event_id);

            return array('metadata'=>$new_meta, 'data'=>$new_data);
        }
    }


	public function redcap_save_record( $project_id, $record, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1) {
        $this->initialize();

        if (empty($this->evalLogic) ||
            \REDCap::evaluateLogic($this->evalLogic,$project_id,$record,$event_id,$repeat_instance) == false
        ) {
            // Skip - nothing to do here
            $this->emDebug("Skip");
            return;
        }

        // Make a PDF
        $this->emDebug("Making PDF", self::$MAKING_PDF);
        self::$MAKING_PDF = true;

        // Always start with the 'first form' as the template
        $first_form = $this->inputForms[0];
        $pdf = \REDCap::getPDF($record, $first_form, $event_id,false, $repeat_instance,
            true,$this->header,$this->footer);

        // Get a temp filename
        $filename = APP_PATH_TEMP . date('YmdHis') . "_" .
            $this->PREFIX . "_" .
            $record . ".pdf";

        // Make a file with the PDF
        file_put_contents($filename, $pdf);

        // Upload to file_field to EDOCS
        $edoc_id = $this->framework->saveFile($filename);
        // $this->emDebug($edoc_id);

        // Remove it from TEMP
        unlink($filename);

        // Save it to the record
        if (!empty($this->destinationFileField)) {
            $data = [
                $record => [
                    $event_id => [
                        $this->destinationFileField => $edoc_id
                    ]
                ]
            ];

            $result = \Records::saveData(
                $project_id,
                'array',        //$dataFormat = (isset($args[1])) ? strToLower($args[1]) : 'array';
                $data,          // = (isset($args[2])) ? $args[2] : "";
                'normal',       //$overwriteBehavior = (isset($args[3])) ? strToLower($args[3]) : 'normal';
                'YMD',          //$dateFormat = (isset($args[4])) ? strToUpper($args[4]) : 'YMD';
                'flat',         //$type = (isset($args[5])) ? strToLower($args[5]) : 'flat';
                $group_id,      // = (isset($args[6])) ? $args[6] : null;
                true,           //$dataLogging = (isset($args[7])) ? $args[7] : true;
                true,           //$performAutoCalc = (isset($args[8])) ? $args[8] : true;
                true,           //$commitData = (isset($args[9])) ? $args[9] : true;
                false,          //$logAsAutoCalculations = (isset($args[10])) ? $args[10] : false;
                true,           //$skipCalcFields = (isset($args[11])) ? $args[11] : true;
                [],             //$changeReasons = (isset($args[12])) ? $args[12] : array();
                false,          //$returnDataComparisonArray = (isset($args[13])) ? $args[13] : false;
                false,          //**** $skipFileUploadFields = (isset($args[14])) ? $args[14] : true;
                false,          //$removeLockedFields = (isset($args[15])) ? $args[15] : false;
                false,          //$addingAutoNumberedRecords = (isset($args[16])) ? $args[16] : false;
                false           //$bypassPromisCheck = (isset($args[17])) ? $args[17] : false;
            );

            \REDCap::logEvent($this->getModuleName(),$this->destinationFileField .
                " was updated with a new PDF containing data from " .
                implode(",",$this->inputForms),"",$record,$event_id);
        }


        // // Save to file repository
        // if ($this->saveToFileRepo) {
        //
        // }

        self::$MAKING_PDF = false;
    }


}
