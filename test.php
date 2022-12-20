<?php
namespace Stanford\MultiSignatureConsent;

/** @var MultiSignatureConsent $module */

require "vendor/autoload.php";
xdebug_break();
$module->emDebug("HERE!!!", $_POST);

if (isset($_POST['filename'])) {
    $filename = htmlspecialchars($_POST['filename'], ENT_QUOTES);
    $output = str_replace(".pdf","-2.pdf", $filename);

    $module->emDebug($filename);
    $attachedPdfs = $module->getProjectSetting($filename);
    $module->emDebug($attachedPdfs);

    $pdfMerge = new \Karriere\PdfMerge\PDFMerge();
    foreach ($attachedPdfs as $pdf) {
        if ($pdf['location'] == 'before') $pdfMerge->add($pdf['path']);
    }
    $pdfMerge->add($filename);
    foreach ($attachedPdfs as $pdf) {
        if ($pdf['location'] == 'after') $pdfMerge->add($pdf['path']);
    }
    $pdfMerge->merge($output);

    $module->removeProjectSetting($filename);
    echo json_encode(["success", $output]);
    exit();
}


//var_dump ($_POST);



//
echo "Hi";
//
//$paths = [
//    "/var/www/html/temp/Empowered_Relief_Project_SoW.pdf",
//    "/var/www/html/temp/Your_hotel_reservation_for_REDCapCon_0920.pdf"
//];
//
////var_dump($module);
//
//$pdfMerge = new \Karriere\PdfMerge\PdfMerge();
//
//foreach ($paths as $path) {
//    var_dump($path);
//    $pdfMerge->add($path);
//}
//
//$pdfMerge->merge('/var/www/html/temp/output.pdf');


//var_dump($pdfMerge);
