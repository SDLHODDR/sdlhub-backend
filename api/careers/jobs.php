<?php
/*
 * GET open jobs (legacy careers/dashboard.php, utility.php?getsearchdd, view_jd.php).
 *   view=list                 -> jobs + filter options; filter=location|department|designation&values=1,2
 *                                and/or q=text (title, location, department).
 *   view=detail&rec_id=       -> job description, education, skills, experience, related jobs,
 *                                whether the logged-in candidate applied / can apply.
 * Job lists are public; apply status needs a logged-in candidate.
 */
require_once __DIR__ . '/careersCommon.php';

const CAREERS_JOB_SQL = "SELECT HR.ID, HR.JD_ID, HR.ORG_LOC_ID, HR.DEPT_ID, HR.DESIG_ID, HR.REQ_DATE,
        TO_CHAR(HR.REQ_DATE, 'YYYY-MM-DD') AS REQ_ISO,
        GET_DIVISION_NAME(HO.DIVSN_ID) AS DIVISION, GET_DEPT_NAME(HO.DEPT_ID) AS DEPARTMENT,
        GET_DESIGN_NAME(HO.DESI_ID) AS DESIGNATION, HOL.GEO_DESC AS LOCATION,
        SUBSTR(J.DESCR, 1, 400) AS ABOUT
    FROM HR_RECRUITMENT HR
    INNER JOIN HR_ORGANOGRAM HO ON HO.ID = HR.ORG_ID
    LEFT JOIN HR_ORGANOGRAM_LOC HOL ON HOL.ID = HR.ORG_LOC_ID
    LEFT JOIN HR_JD J ON J.ID = HR.JD_ID
    WHERE HR.STATUS IN (" . CAREERS_OPEN_JOB_STATUSES . ")";

function careersPostedAgo($date)
{
    if (!$date) return '';
    $posted = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$date);
    if (!$posted) return '';
    $diff = $posted->diff(new DateTimeImmutable('today'));
    if ($diff->y > 0) return $diff->y === 1 ? '1 year ago' : "{$diff->y} years ago";
    if ($diff->m > 0) return $diff->m === 1 ? '1 month ago' : "{$diff->m} months ago";
    if ($diff->d > 6) { $weeks = intdiv($diff->d, 7); return $weeks === 1 ? '1 week ago' : "{$weeks} weeks ago"; }
    if ($diff->d > 0) return $diff->d === 1 ? 'yesterday' : "{$diff->d} days ago";
    return 'today';
}

function careersJobCard(array $row)
{
    $about = careersText($row['ABOUT'] ?? '');
    return [
        'id' => $row['ID'],
        'division' => careersText($row['DIVISION'] ?? ''),
        'location' => careersText($row['LOCATION'] ?? ''),
        'title' => careersText($row['DESIGNATION'] ?? ''),
        'department' => careersText($row['DEPARTMENT'] ?? ''),
        'about' => mb_strlen($about) > 160 ? mb_substr($about, 0, 160) . '...' : $about,
        'posted' => careersPostedAgo($row['REQ_ISO'] ?? ''),
    ];
}

careersRunRead(static function () {
    $view = trim((string)($_GET['view'] ?? 'list'));

    if ($view === 'list') {
        $where = '';
        $binds = [];
        $filter = trim((string)($_GET['filter'] ?? ''));
        $columns = ['location' => 'HR.ORG_LOC_ID', 'department' => 'HR.DEPT_ID', 'designation' => 'HR.DESIG_ID'];
        $values = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['values'] ?? ''))), static fn($v) => $v > 0));
        if (isset($columns[$filter]) && $values) {
            $where .= ' AND ' . $columns[$filter] . ' IN (' . implode(',', array_slice($values, 0, 50)) . ')';
        }
        $search = trim((string)($_GET['q'] ?? ''));
        if ($search !== '') {
            careersAssertLength($search, 100, 'Search');
            $where .= " AND (UPPER(GET_DESIGN_NAME(HO.DESI_ID)) LIKE :q OR UPPER(HOL.GEO_DESC) LIKE :q
                OR UPPER(GET_DEPT_NAME(HO.DEPT_ID)) LIKE :q)";
            $binds[':q'] = '%' . strtoupper($search) . '%';
        }
        $jobs = careersRows(CAREERS_JOB_SQL . $where . " ORDER BY HR.REQ_DATE DESC, HR.ID DESC", $binds);

        $options = static fn($sql) => array_map(static fn($r) => ['value' => $r['ID'], 'label' => careersText($r['LABEL'])],
            careersRows($sql));
        $open = "FROM HR_RECRUITMENT HR WHERE HR.STATUS IN (" . CAREERS_OPEN_JOB_STATUSES . ")";
        return ['Jobs fetched.', [
            'jobs' => array_map('careersJobCard', $jobs),
            'filters' => [
                'location' => $options("SELECT DISTINCT HR.ORG_LOC_ID AS ID, HOL.GEO_DESC AS LABEL
                    FROM HR_RECRUITMENT HR INNER JOIN HR_ORGANOGRAM_LOC HOL ON HOL.ID = HR.ORG_LOC_ID
                    WHERE HR.STATUS IN (" . CAREERS_OPEN_JOB_STATUSES . ") ORDER BY 2"),
                'department' => $options("SELECT DISTINCT HR.DEPT_ID AS ID, GET_DEPT_NAME(HR.DEPT_ID) AS LABEL {$open} ORDER BY 2"),
                'designation' => $options("SELECT DISTINCT HR.DESIG_ID AS ID, GET_DESIGN_NAME(HR.DESIG_ID) AS LABEL {$open} ORDER BY 2"),
            ],
        ]];
    }

    if ($view === 'detail') {
        $recId = (int)($_GET['rec_id'] ?? 0);
        if ($recId <= 0) {
            throw new CareersException('Job not found.', 404);
        }
        $job = careersRow(CAREERS_JOB_SQL . " AND HR.ID = :id", [':id' => (string)$recId]);
        if (empty($job['ID'])) {
            throw new CareersException('This job is no longer open.', 404);
        }
        $jdBind = [':jd' => (string)$job['JD_ID']];
        $jd = careersRow("SELECT SH_DESC, DESCR, MIN_EXP, MAX_EXP FROM HR_JD WHERE ID = :jd", $jdBind);
        $description = careersRow("SELECT DESCR, DESCR1, DESCR2, DESCR3, DESCR4 FROM HR_JD_DESCRIPTION WHERE JD_ID = :jd AND ROWNUM = 1", $jdBind);
        $responsibilities = careersSafeHtml(implode('', array_map(static fn($k) => (string)($description[$k] ?? ''),
            ['DESCR', 'DESCR1', 'DESCR2', 'DESCR3', 'DESCR4'])));
        $education = careersRows("SELECT Q.QUA_DESC, E.COMMENTS FROM HR_JD_EDU_DET E
            LEFT JOIN HR_QUALIFICATIONS Q ON Q.QUA_ID = E.QUA_ID WHERE E.JD_ID = :jd ORDER BY E.ID", $jdBind);
        $skills = careersRows("SELECT B.CAPA_CODE, B.CAPA_DESC, C.CAPALVL_DESC FROM HR_JD_CAPABILITIES A
            INNER JOIN HR_CAPABILITIES B ON B.CAPA_ID = A.CAPA_ID
            LEFT JOIN HR_CAPA_LEVEL C ON C.CAPALVL_ID = A.CAPALVL_ID
            WHERE A.JD_ID = :jd ORDER BY A.CAPALVL_ID DESC, A.ID", $jdBind);
        $related = careersRows("SELECT * FROM (" . CAREERS_JOB_SQL . " AND HR.DESIG_ID = :desig AND HR.ID <> :id
            ORDER BY HR.REQ_DATE DESC) WHERE ROWNUM <= 4", [':desig' => (string)$job['DESIG_ID'], ':id' => (string)$recId]);

        $applicant = null;
        $candidateId = (int)($_SESSION['careers']['candidateId'] ?? 0);
        if ($candidateId > 0) {
            $candidate = careersCandidate();
            $applied = careersRow("SELECT TO_CHAR(APL_DATE, 'DD-Mon-YYYY') AS APL_DATE FROM HR_CAN_APPLICATION_DETAILS
                WHERE CAN_ID = :can AND REQ_ID = :id AND ROWNUM = 1", [':can' => (string)$candidateId, ':id' => (string)$recId]);
            $applicant = [
                'appliedOn' => $applied['APL_DATE'] ?? null,
                'verified' => careersIsVerified($candidate),
                'blocked' => ($candidate['STATUS'] ?? '') === 'B',
                'completeness' => careersCompleteness($candidateId),
            ];
        }

        return ['Job fetched.', [
            'job' => careersJobCard($job) + [
                'heading' => careersText($jd['SH_DESC'] ?? '') ?: careersText($job['DESIGNATION'] ?? ''),
                'summary' => careersText($jd['DESCR'] ?? ''),
                'responsibilitiesHtml' => $responsibilities,
                'education' => array_map(static fn($r) => [
                    'qualification' => careersText($r['QUA_DESC'] ?? ''), 'comments' => careersText($r['COMMENTS'] ?? ''),
                ], $education),
                'skills' => array_map(static fn($r) => [
                    'code' => careersText($r['CAPA_CODE'] ?? ''), 'description' => careersText($r['CAPA_DESC'] ?? ''),
                    'level' => careersText($r['CAPALVL_DESC'] ?? ''),
                ], $skills),
                'minExperience' => $jd['MIN_EXP'] ?? '',
                'maxExperience' => $jd['MAX_EXP'] ?? '',
            ],
            'related' => array_map('careersJobCard', $related),
            'applicant' => $applicant,
        ]];
    }

    throw new CareersException('Invalid view.', 400);
}, 'careers/jobs.php');
