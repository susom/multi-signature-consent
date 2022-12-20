<?php
namespace Stanford\MultiSignatureConsent;


require_once "emLoggerTrait.php";

//require_once 'vendor/autoload.php';


class MultiSignatureConsent extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $evalLogic;
    public $destinationFileField;
    public $inputForms = [];
    public $formOptions = [];
    public $header;
    public $footer;
    public $saveToFileRepo;
    public $saveToExternalServer;
    public $saveToAsSurvey;
    public $attachedPdfs = [];

    private static $MAKING_PDF = false;

    private static $KEEP_RECORD_ID_FIELD = false;
    private static $KEEP_PAGE_BREAKS = false;
    private static $SAVE_ONLY_COMPLETED = false;

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
        $this->saveToExternalStorage= $this->getProjectSetting('save-to-external-storage');
        $this->saveToAsSurvey       = $this->getProjectSetting('save-to-as-survey');
        $this::$KEEP_PAGE_BREAKS    = $this->getProjectSetting('keep-page-breaks');
        $this::$KEEP_RECORD_ID_FIELD= $this->getProjectSetting('keep-record-id-field');
        $this::$SAVE_ONLY_COMPLETED = $this->getProjectSetting('save-only-completed');
        $this->inputForms           = [];
        $this->formOptions          = []; //$this->getSubSettings('instance');

        // TODO: Validate that form-names are valid
        $instances = $this->getSubSettings('instance');
        foreach ($instances as $instance) {
            $this->inputForms[] = $instance['form-name'];
            $this->formOptions[$instance['form-name']] = $instance;
        }
        // $this->emDebug($this->formOptions);
    }


    public function redcap_every_page_before_render() {
        if (PAGE == 'FileRepository/index.php') {
            global $Proj;
            $this->initialize();
            $firstForm = $this->inputForms[0];
            if (empty($firstForm)) return;
            if (empty($Proj->forms[$firstForm]['survey_id'])) return;
            $survey_id = $Proj->forms[$firstForm]['survey_id'];
            $Proj->surveys[$survey_id]['pdf_auto_archive']=true;
        }
    }


    public function redcap_save_record( $project_id, $record, $instrument, $event_id, $group_id = NULL,
                                        $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1)
    {
        try {

            global $Proj;
            $this->initialize();

            // Make sure we are in one of the input forms
            if (!in_array($instrument, $this->inputForms)) {
                $this->emDebug("$instrument is not in " . implode(",", $this->inputForms) . " -- skipping");
                return false;
            }

            $this->emDebug("Saving record $record on form $instrument, event $event_id with logic $this->evalLogic");
            // $event_name = $Proj->longitudinal ? \REDCap::getEventNames(true,true,$event_id) : null;

            if (empty($this->evalLogic) || !\REDCap::evaluateLogic($this->evalLogic, $project_id, $record, $event_id)) {
                // Skip - nothing to do here
                $this->emDebug("Missing or false logic -- skipping");
                return false;
            }

            // Omit incomplete forms if set
            if (self::$SAVE_ONLY_COMPLETED) {
                $form_status_fields = [];
                foreach ($this->inputForms as $form => $form_name) {
                    $form_status_fields[]  = $form_name . "_complete";
                }

                $form_status = \REDCap::getData('array', $record, $form_status_fields, $event_id);
                $new_import_forms = [];
                foreach ($form_status[$record][$event_id] as $k => $v) {
                    //only add back to the inputForm array if the status is complete
                    if ($v == '2') {
                        // remove the '_complete' suffix
                        $derived_form_name = substr($k, 0, -9);
                        $new_import_forms[] = $derived_form_name;
                    }
                }
                $this->emDebug("Updating input forms: " . json_encode($this->inputForms) . " to " . json_encode($new_import_forms));
                $this->inputForms = $new_import_forms; //reset the array
            }

            // Make a PDF
            $this->emDebug("Making PDF", self::$MAKING_PDF);
            self::$MAKING_PDF = true;

            // Crazy bug workaround: https://community.projectredcap.org/questions/129426/error-handling-in-redcap-pdf-functions-redcap-1232.html
            // In order for this next method to succeed, it is required that the global $user_rights be set for the current user
            // This bug was hard to find.  As an aside, survey respondents DO have form export and data export rights
            // but users that do not have them were previously unable to complete execution of this process.
            // Even worse, the getPDF method would fail with a 'die' that would lead to an EM error for a crashed

            // Cache current user_rights and grant current user necessary rights globally
            global $user_rights;
            $cache_user_rights = $user_rights;
            foreach ($this->inputForms as $form_name) {
                $user_rights['forms_export'][$form_name] = 1;
            }
            $user_rights['data_export_tool'] = 1;
            // $this->emDebug("AFTER", $user_rights);

            // Always start with the 'first form' as the template
            $this->emDebug("Forms: " . json_encode($this->inputForms));
            //TODO: Validate form is valid in this event


            $first_form = $this->inputForms[0];
            $last_form = $this->inputForms[count($this->inputForms) -1 ];

            //$this->emDebug("Creating PDF with inputs", $record, $first_form, $event_id, $repeat_instance, $this->header, $this->footer);
            // You might think we should loop through the forms now and build a PDF, but it turned out to be easier to
            // do this inside the getPDF method itself.  So, much of the logic you would except here is now in getPDF.
            $pdf = \REDCap::getPDF($record, $first_form, $event_id, false, $repeat_instance, true, $this->header, $this->footer);
            $this->emDebug("Successfully Retrieved pdf for project $project_id, record $record");


            // TRY TO MERGE PDFs:
            $pdfsToMerge = [];
            foreach ($this->attachedPdfs as $attachedPdf) {
                $path = \Files::copyEdocToTemp($attachedPdf['edoc'],true,true);
                $mime = mime_content_type($path);
                if ($mime == "application/pdf") {
                    $attachedPdf['path'] = $path;
                    $pdfsToMerge[] = $attachedPdf;
                } else {
                    $this->emDebug("Skipping invalid filetype: " . $mime, $attachedPdf);
                }
            }
            $this->emDebug("Created PDF Files for merging", $pdfsToMerge);

            // Restore user rights to previous values
            $user_rights = $cache_user_rights;

            // Get a temp filename
            // $filename = APP_PATH_TEMP . date('YmdHis') . "_" .
            //     $this->PREFIX . "_" .
            //     $record . ".pdf";
            $recordFilename = str_replace(" ", "_", trim(preg_replace("/[^0-9a-zA-Z- ]/", "", $record)));
            $formFilename   = str_replace(" ", "_", trim(preg_replace("/[^0-9a-zA-Z- ]/", "", $Proj->forms[$first_form]['menu'])));
            $filename       = APP_PATH_TEMP . "pid" . $this->getProjectId() .
                "_form" . $formFilename . "_id" . $recordFilename . "_" . date('Y-m-d_His') . ".pdf";

            // Make a file with the PDF
            $this->emDebug("Starting to create a file for the pdf: pid=$project_id, record=$record");
            file_put_contents($filename, $pdf);
            $this->emDebug("Finished creating a file for the pdf: pid=$project_id, record=$record");


            // Unfortunately, I cannot 'merge' the files right now as there is a namespace collision with the PFDF classes
            // Cannot declare class FPDF, because the name is already in use in
            // /var/www/html/modules-local/multi_signature_consent_v9.9.9/vendor/karriere/pdf-merge/tcpi/tcpdi.php
            // So, I'm going to make an internal webcal to a different endpoint to complete the merge

            // Save the pdf file and pre/post processing
            try {
                $this->setProjectSetting($filename, $pdfsToMerge);
                $url = $this->getUrl('test.php', true);
                $this->emDebug("Calling $url");
                $client = new \GuzzleHttp\Client();
                $payload = [
                    'filename' => $filename,
                    'redcap_csrf_token' => $this->getCSRFToken()
                ];
                $this->emDebug($payload);
                $response = $client->request('POST', $url, ['form_params' => $payload]);
                $this->emDebug($response->getStatusCode(),$response->getBody()->getContents());
            } catch (\Exception $e) {
                $this->emError("Exception", $e);
            }

            $this->emDebug("Done"); return false;

            // Add PDF to edocs_metadata table
            $pdfFile = array('name' => basename($filename), 'type' => 'application/pdf',
                'size' => filesize($filename), 'tmp_name' => $filename);
            $this->emDebug("Starting the file upload process with the pdf: pid=$project_id, record=$record");
            $edoc_id = \Files::uploadFile($pdfFile);
            $this->emDebug("Finished uploading pdf: pid=$project_id, record=$record");
            if ($this->saveToExternalStorage) {
                $this->emDebug("Starting the file upload process to auto archiver: pid=$project_id, record=$record");
                $externalFileStoreWrite=\Files::writeFilePdfAutoArchiverToExternalServer( basename($filename), $pdf);
                $this->emDebug("Finished upload process to auto archiver: pid=$project_id, record=$record");
                \REDCap::logEvent($this->getModuleName(), "A PDF (" .
                    basename($filename) .
                    ") has been written to the external storage containing data from " .
                    implode(",", $this->inputForms), "", $record, $event_id);

            }
            // Upload to file_field to EDOCS
            // $edoc_id = $this->framework->saveFile($filename);
            $this->emDebug($edoc_id);

            // Remove it from TEMP
            unlink($filename);

            if ($edoc_id == 0) {
                $this->emError("Unable to get edoc id!");
                return false;
            }

            // Save it to the record
            if (!empty($this->destinationFileField)) {
                $data = [
                    $record => [
                        $event_id => [
                            $this->destinationFileField => $edoc_id
                        ]
                    ]
                ];

                $this->emDebug("Saving the file to the record: pid=$project_id, record=$record");
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

                $this->emDebug("Finished saving file to the record: pid=$project_id, record=$record with return ", json_encode($result));
                \REDCap::logEvent($this->getModuleName(), $this->destinationFileField .
                    " was updated with a new PDF containing data from " .
                    implode(",", $this->inputForms), "", $record, $event_id);
            }


            // // Save to file repository
            if ($this->saveToFileRepo) {
                $pdf_form = empty($this->saveToAsSurvey) ? $last_form : $this->saveToAsSurvey;
                if (empty($Proj->forms[$pdf_form]['survey_id'])) {
                    \REDCap::logEvent($this->getModuleName() . " Error",
                        "Cannot save to file repository unless the pdf_form is a survey ($pdf_form)", "", $record, $event_id);
                } else {
                    // Add values to redcap_surveys_pdf_archive table
                    $survey_id = $Proj->forms[$pdf_form]['survey_id'];

                    $ip          = \System::clientIpAddress();
                    $nameDobText = $this->getModuleName();
                    $versionText = $typeText = "";
                    // $sql         = "replace into redcap_surveys_pdf_archive (doc_id, record, event_id, survey_id, instance, identifier, version, type, ip) values
                    //         ($edoc_id, '" . db_escape($record) . "', '" . db_escape($event_id) . "', '" . db_escape($survey_id) . "', '" . db_escape($repeat_instance) . "',
                    //         " . checkNull($nameDobText) . ", " . checkNull($versionText) . ", " . checkNull($typeText) . ", " . checkNull($ip) . ")";
                    // $q           = db_query($sql);
                    $q = $this->query("insert into redcap_surveys_pdf_archive (doc_id, record, event_id, survey_id, instance, identifier, version, type, ip) values
				        (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                        $edoc_id,
                        db_escape($record),
                        db_escape($event_id),
                        db_escape($survey_id),
                        db_escape($repeat_instance),
                        checkNull($nameDobText),
                        checkNull($versionText),
                        checkNull($typeText),
                        checkNull($ip)
                    ]);
                    $this->emDebug($q);
                }
            }

            self::$MAKING_PDF = false;
        }
        catch(\Exception $e) {
            $this->emError($e->getMessage(), "Line: " . $e->getLine(), $e->getTraceAsString());
        }
    }


    // We override the redcap_pdf function with a flag to know when to inject our code so that we don't mess up any
    // native PDF generation for the project
	public function redcap_pdf ($project_id, $metadata, $data, $instrument = NULL, $record = NULL, $event_id = NULL, $instance = 1 ) {
        // Do nothing for non-EM mediated PDF calls
        if (!self::$MAKING_PDF) return array('metadata'=>$metadata, 'data'=>$data);
        // We are calling this method from this EM
        try {
            $this->emDebug("In PDF Hook for " . $this->getModuleName(), $data, $instrument, $record, $event_id, $instance, $this->inputForms, $this->evalLogic);

            // Build metadata from all forms
            global $Proj;

            // Get fields in all forms
            $new_meta = [];
            $fields = [];
            foreach ($Proj->metadata as $field_name => $field_meta) {
                $formName = $field_meta['form_name'];
                if (in_array($formName, $this->inputForms)) {
                    // This field is in our form

                    // Skip form_complete fields
                    if ($formName . "_complete" == $field_meta['field_name']) {
                        continue;
                    }

                    // Skip @HIDDEN-PDF fields
                    if (strpos($field_meta['misc'], '@HIDDEN-PDF') !== FALSE) {
                        continue;
                    }

                    // Processing Metadata PDF fields
                    $include_where = $this->formOptions[$formName]['include-metadata-pdf'] ?? "";
                    if ($field_meta['element_type'] == 'descriptive' &&
                        !empty($field_meta['edoc_id']) &&
                        $include_where)
                    {
                        // TODO: Ensure field is visible if it has branching logic!
                        $this->emDebug("We have found a metadata PDF");
                        $pdf=[
                            'location' => $include_where,
                            'edoc'  => $field_meta['edoc_id']
                        ];
                        $this->attachedPdfs[] = $pdf;
                    };

                    // Processing User Uploaded PDF fields
                    $include_where = $this->formOptions[$formName]['include-user-uploaded-pdf'] ?? "";
                    if ($field_meta['element_type'] == 'file' &&
                        !empty($data[$record][$event_id][$field_name]) &&
                        $include_where)
                    {
                        $this->emDebug("We have found a user-uploaded PDF");
                        $pdf=[
                            'location' => $include_where,
                            'edoc'  => $data[$record][$event_id][$field_name]
                        ];
                        $this->attachedPdfs[] = $pdf;
                    };


                    // Skip record id field unless told otherwise
                    if (!$this::$KEEP_RECORD_ID_FIELD &&
                        $field_meta['field_order'] == 1
                    ) {
                        continue;
                    }

                    // In order to get all signatures on 'same page' of PDF
                    // I make it appear as all fields are on the first form
                    if (!$this::$KEEP_PAGE_BREAKS &&
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
            $this->emDebug("Getting updated data for $record in event $event_id: " . json_encode($fields));
            $new_data = \REDCap::getData('array', $record, $fields, $event_id);

            return array('metadata'=>$new_meta, 'data'=>$new_data);

        } catch (\Exception $e) {
            $this->emError("PDF Exception: " . $e->getMessage(), "Line: " . $e->getLine(), $e->getTraceAsString());
            return array('metadata' => $metadata, 'data' => $data);
        }
    }


}
