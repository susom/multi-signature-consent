# Multi Signature Consent

A REDCap External Module that allows you to create a single PDF that contains data from multiple REDCap forms.  It was designed to allow participant and coordinator consent signatures to be merged into a single final PDF document.

When your defined logic is true, the module will merge forms into a single PDF and optionally save the PDF to the file repository (or to a file-upload field in the project).

You can couple this with an Alert and Notification to then send a copy of the combined PDF to a participant.

Use this link to see a demo: https://redcap.stanford.edu/surveys/?s=KXFHHCPNMP

*WARNING*: There is a troublesome configuration issue with the `redcap_pdf` hook that is used by this module.  If, after installing this module and configuring it, nothing seems to happen when your logic evaluates to true then you may have the redcap_pdf function defined as part of your 'hook framework'.  Search your `hooks_functions.php` or  `hooks` folder if you have one in the root directory of your REDCap install and comment out the definition for the redcap pdf function.  It will start with `function redcap_pdf`.  The following community post describes the issue:
https://community.projectredcap.org/questions/108125/combining-multiple-completed-forms-into-a-single-p.html
