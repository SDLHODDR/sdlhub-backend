<?php
/* Dropdown options of the application form (legacy application_form.php arrays and lookups). */

const CAREERS_TITLES = ['1' => 'Mr.', '2' => 'Miss.', '3' => 'Mrs.', '4' => 'Dr.', '5' => 'Vd.'];
const CAREERS_QUALIFICATIONS = [
    'SSC' => 'SSC / 10th', 'HSC' => 'HSC / 10+2 / 12th', 'Diploma' => 'Diploma',
    'Bachelors Degree' => 'Bachelors Degree', 'Masters Degree' => 'Masters Degree',
];
const CAREERS_RELATIONS = ['Father', 'Mother', 'Son', 'Daughter', 'Spouse'];
const CAREERS_DEPENDENT = ['Dependant', 'Non-Dependant', 'Deceased'];
const CAREERS_SOCIAL_MEDIA = ['FaceBook', 'WhatsApp', 'Twitter', 'Instagram', 'LinkedIn'];

function careersArrayOptions(array $values)
{
    // A plain list uses each label as its value; a map ('1' => 'Mr.') uses its keys
    // (PHP stores numeric string keys as integers, so check the shape, not the key type).
    $isList = array_is_list($values);
    $options = [];
    foreach ($values as $key => $label) {
        $options[] = ['value' => $isList ? $label : (string)$key, 'label' => $label];
    }
    return $options;
}

function careersLookupOptions($sql)
{
    return array_map(static fn($r) => ['value' => (string)$r['V'], 'label' => careersText($r['L'])], careersRows($sql));
}

function careersProfileOptions()
{
    return [
        'titles' => careersArrayOptions(CAREERS_TITLES),
        'genders' => careersLookupOptions("SELECT GEND_ID AS V, GEND_DESC AS L FROM HR_GENDER ORDER BY GEND_ID"),
        'maritalStatus' => careersLookupOptions("SELECT MARI_ID AS V, MARI_DESC AS L FROM HR_MARITAL ORDER BY MARI_ID"),
        'states' => careersLookupOptions("SELECT GSTAT_CODE AS V, GSTAT_DESC AS L FROM HR_BCS_GST_STATES ORDER BY GSTAT_DESC"),
        'countries' => careersLookupOptions("SELECT DESCR AS V, DESCR AS L FROM EPPLIVE.BCS_COUNTRY ORDER BY DESCR"),
        'qualifications' => careersArrayOptions(CAREERS_QUALIFICATIONS),
        'references' => careersLookupOptions("SELECT REQREF_ID AS V, INITCAP(REQREF_DESC) AS L FROM HR_REQREFERENCES ORDER BY 2"),
        'relations' => careersArrayOptions(CAREERS_RELATIONS),
        'dependent' => careersArrayOptions(CAREERS_DEPENDENT),
        'socialMedia' => careersArrayOptions(CAREERS_SOCIAL_MEDIA),
        // HR_CAN_LANGUAGE.LANGUAGE holds 10 bytes, so longer names cannot be saved.
        'languages' => careersLookupOptions("SELECT LANG_DESC AS V, LANG_DESC AS L FROM HR_LANGUAGE
            WHERE LENGTHB(LANG_DESC) <= 10 ORDER BY LANG_DESC"),
    ];
}

/** Value must be one of the options; returns it. */
function careersAssertOption($value, array $options, $label, $required = true)
{
    $value = trim((string)$value);
    if ($value === '') {
        if ($required) throw new CareersException("{$label} is required.", 422);
        return '';
    }
    if (!in_array($value, array_column($options, 'value'), true)) {
        throw new CareersException("Please select a valid {$label}.", 422);
    }
    return $value;
}
