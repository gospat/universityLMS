<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'ULMS academics';
$string['enableacademics'] = 'Enable academic structure management';
$string['enableacademicsdesc'] = 'Allows the ULMS academic structure services to be enabled for university setup.';
$string['currentsessioncode'] = 'Current session code';
$string['currentsessioncodedesc'] = 'Optional code used to identify the active academic session.';
$string['generalsettings'] = 'General settings';
$string['generalsettingsdesc'] = 'Configure the foundation settings for the ULMS academic structure.';
$string['academicstructuresetup'] = 'Academic structure setup';
$string['academicstructuresetupdesc'] = 'Use the links below to manage the core academic structure records for the university.';
$string['academictoolsoverview'] = 'Academic setup tools';
$string['academicsessions'] = 'Academic sessions';
$string['active'] = 'Active';
$string['actions'] = 'Actions';
$string['allstatuses'] = 'All statuses';
$string['awardtype'] = 'Award type';
$string['bulkimport'] = 'CSV bulk import';
$string['bulkimportdesc'] = 'Upload CSV files for colleges, departments, programmes, and courses.';
$string['csvdownloadtemplate'] = 'Download CSV template';
$string['csvemptyfile'] = 'The uploaded CSV file does not contain any data rows.';
$string['csvactioncreate'] = 'Create';
$string['csvactioninvalid'] = 'Invalid';
$string['csvactionupdate'] = 'Update';
$string['cannotdeletewithdependencies'] = 'This record cannot be deleted because it still has dependent records: {$a}.';
$string['clearfilters'] = 'Clear filters';
$string['code'] = 'Code';
$string['confirmcsvimport'] = 'Confirm CSV import';
$string['confirmdeleteentity'] = 'Are you sure you want to delete "{$a}"?';
$string['csventity'] = 'Import entity';
$string['csvimportcompleted'] = 'CSV import completed. Processed: {$a->processed}, created: {$a->created}, updated: {$a->updated}.';
$string['csvimporterrors'] = 'Some rows could not be imported.';
$string['csvmissingrequired'] = 'Line {$a}: required code or name column is missing.';
$string['csvparentmissing'] = 'Line {$a}: the parent entity could not be resolved from the CSV data.';
$string['csvpreviewfixerrors'] = 'Fix the invalid rows in the CSV preview before confirming the import.';
$string['csvpreviewheading'] = 'Import preview';
$string['csvpreviewpayloaderror'] = 'The import preview could not be prepared. Please upload the CSV file again.';
$string['csvpreviewsummary'] = 'Preview ready. Rows processed: {$a->processed}, valid: {$a->valid}, invalid: {$a->invalid}.';
$string['csvtemplatedesc'] = 'Use the template links below to download the correct column structure before importing.';
$string['courses'] = 'Courses';
$string['csvonesingle'] = 'Add / Create one course at a time';
$string['csvupload'] = 'CSV file';
$string['csvvalidationpassed'] = 'Validated successfully.';
$string['departments'] = 'Departments';
$string['durationyears'] = 'Duration in years';
$string['enddate'] = 'End date';
$string['entityrecords'] = 'Existing records';
$string['exportsummarycsv'] = 'Export summary as CSV';
$string['filterlabel'] = 'Filter records';
$string['filterrecordsdesc'] = 'Search, sort, and narrow the records shown below.';
$string['importseedhelp'] = 'You can also import academic structure data using the CLI seed importer.';
$string['invaliddaterange'] = 'The end date must be later than the start date.';
$string['manageacademics'] = 'Manage academic structure';
$string['manageacademicstructure'] = 'Manage academic structure';
$string['manageacademicstructuredesc'] = 'Open the ULMS academic structure management pages.';
$string['managecoursemappings'] = 'Programme course mappings';
$string['managecoursemappingsdesc'] = 'Link Moodle courses to academic programmes and optional semesters for reporting and analytics.';
$string['manageimport'] = 'Academic CSV import';
$string['manageimportdesc'] = 'Open the academic structure CSV import page.';
$string['managereports'] = 'Academic summary report';
$string['managereportsdesc'] = 'Open the academic structure summary report.';
$string['mappingcoursetype'] = 'Course type';
$string['mappingcoursetypecore'] = 'Core';
$string['mappingcoursetypeelective'] = 'Elective';
$string['mappingcoursetypegeneral'] = 'General studies';
$string['mappingbulkimportdesc'] = 'Upload a CSV file to preview and import multiple programme course mappings at once.';
$string['mappingbulkimportheading'] = 'Bulk mapping import';
$string['mappingconfirmimport'] = 'Confirm mapping import';
$string['mappingcsvrequired'] = 'Line {$a}: programme and Moodle course could not be resolved from the CSV data.';
$string['mappingcsvsemesterinvalid'] = 'Line {$a}: the semester could not be resolved from the CSV data.';
$string['mappingdeleted'] = 'Course mapping deleted successfully.';
$string['mappingduplicate'] = 'A mapping already exists for this programme and Moodle course.';
$string['mappingdownloadtemplate'] = 'Download mapping CSV template';
$string['mappingexportcsv'] = 'Export mappings as CSV';
$string['mappingactivefilters'] = 'Active filters';
$string['mappingcurrentsort'] = 'Sort: {$a}';
$string['mappingfilterdepartmentreset'] = 'The selected department filter was cleared because it does not belong to the active college filter.';
$string['mappingfilterprogrammereset'] = 'The selected programme filter was cleared because it does not belong to the active college or department filter.';
$string['mappingfiltersheading'] = 'Filter mappings';
$string['mappingformhierarchyhint'] = 'Optionally choose a college and department first to narrow the programme list before saving the mapping.';
$string['mappingcoursescount'] = 'Mapped courses';
$string['mappingcorecount'] = 'Core mappings';
$string['mappingimportpreview'] = 'Mapping import preview';
$string['mappinginvalidcourse'] = 'The selected Moodle course could not be found.';
$string['mappingiscore'] = 'Core course';
$string['mappingmoodlecourse'] = 'Moodle course';
$string['mappingcreatenewcourse'] = '+ Create new course →';
$string['mappingprogrammescount'] = 'Mapped programmes';
$string['mappingpreviewimport'] = 'Preview mapping import';
$string['mappingrequiredfields'] = 'Programme and Moodle course are required for a course mapping.';
$string['mappingresultssummary'] = 'Showing {$a->start}-{$a->end} of {$a->total} mappings.';
$string['mappingsaved'] = 'Course mapping saved successfully.';
$string['mappingsummaryheading'] = 'Mapping summary';
$string['mappingstotal'] = 'Total mappings';
$string['addmappingheading'] = 'Add course mapping';
$string['applymappingfilters'] = 'Apply filters';
$string['canceleditmapping'] = 'Cancel edit';
$string['editmappingheading'] = 'Edit course mapping';
$string['savemapping'] = 'Save mapping';
$string['mappingupdated'] = 'Course mapping updated successfully.';
$string['nocoursemappings'] = 'No programme course mappings have been created yet.';
$string['updatemapping'] = 'Update mapping';
$string['ulms_academics:manageacademics'] = 'Manage the full ULMS academic structure';
$string['ulms_academics:viewreports'] = 'View academic structure reports';
$string['ulms_academics:managefaculties'] = 'Manage ULMS colleges';
$string['ulms_academics:managedepartments'] = 'Manage ULMS departments';
$string['ulms_academics:viewstructure'] = 'View ULMS academic structure';
$string['faculties'] = 'Colleges';
$string['inactive'] = 'Inactive';
$string['invalidentity'] = 'Unsupported academic entity selected.';
$string['iscurrent'] = 'Current';
$string['manageentitydesc'] = 'Create or update {$a} records using the form below.';
$string['newrecordheading'] = 'Add new record';
$string['parentrecord'] = 'Parent record';
$string['line'] = 'Line';
$string['previewcsvimport'] = 'Preview CSV import';
$string['programmes'] = 'Programmes';
$string['privacy:metadata'] = 'The ULMS academics plugin stores university academic structure data.';
$string['notset'] = 'Not set';
$string['norecordsfound'] = 'No records found for the current filters.';
$string['paginationperpage'] = 'Records per page';
$string['sortdirection'] = 'Sort direction';
$string['sortfield'] = 'Sort by';
$string['recordsaved'] = 'Academic structure record saved successfully.';
$string['recorddeleted'] = 'Academic structure record deleted successfully.';
$string['recordnotfound'] = 'The requested record was not found.';
$string['returntooverview'] = 'Return to academic structure overview';
$string['search'] = 'Search';
$string['selectentitytoimport'] = 'Choose which academic entity the CSV file contains.';
$string['semesters'] = 'Semesters';
$string['startdate'] = 'Start date';
$string['status'] = 'Status';
$string['summarycount'] = 'Count';
$string['summaryentity'] = 'Entity';
$string['summaryreport'] = 'Academic structure summary';
$string['summaryreportdesc'] = 'Review a quick summary of the configured academic structure records.';
$string['timecreated'] = 'Created';
$string['timemodified'] = 'Last updated';
$string['unknownentity'] = 'Unknown entity';
$string['validation'] = 'Validation';

$string['levels'] = 'Study levels';
$string['level'] = 'Study level';
$string['recordcreated'] = 'Record created successfully.';
$string['recordupdated'] = 'Record updated successfully.';
$string['recordnotsaved'] = 'An error occurred while saving the record. Please try again.';
$string['mappings'] = 'Course mappings';
$string['mappingrequiredfieldprogramme'] = 'Programme is required.';
$string['mappingrequiredfieldcourse'] = 'Moodle course is required.';
$string['mappinginvalidsitecourse'] = 'The system site course cannot be used in academic mappings.';
$string['mappinginvalidlevel'] = 'The selected study level does not exist.';
$string['mappinglevelwide'] = 'All levels (level-wide)';
$string['mappingcsvinvalidlevel'] = 'Line {$a}: the study level code does not exist in the academic levels table.';
$string['mappingcolumnlevel'] = 'Level';
$string['mappingcolumnsession'] = 'Academic Session';
$string['mappingfilterlevelreset'] = 'The applied level filter did not match any active level and has been reset.';
$string['mappingfiltersessionreset'] = 'The applied academic session filter did not match any session and has been reset.';
$string['mappingcolumnlecturers'] = 'Lecturers';
$string['mappingmanagelecturers'] = 'Manage allocations';
$string['mappinglecturersempty'] = 'No lecturers assigned';
