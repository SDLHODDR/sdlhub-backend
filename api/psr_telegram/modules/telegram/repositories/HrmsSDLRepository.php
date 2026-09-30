<?php

// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/../../../core/BaseHrmsSDLRepository.php';
require_once __DIR__ . "/../../../../config/functions.php"; 

class HrmsSDLRepository extends BaseHrmsSDLRepository
{
    public function getCompanies()
    {
        $sql = "select COMP_ID, COMP_DESC  from HR_COMPANY order by 1";

        $stmt = $this->execute($sql);

        return $this->fetchAll($stmt);
    }

    public function getDivision()
    {
        $sql = "select DIVSN_ID, DIVSN_DESC from HR_DIVISIONS order by 1";

        $stmt = $this->execute($sql);

        return $this->fetchAll($stmt);
    }

    public function getDepartments()
    {
        $sql = "select DEPT_ID,DEPT_DESC from HR_DEPARTMENT order by 1";

        $stmt = $this->execute($sql);

        return $this->fetchAll($stmt);
    }

    public function getHRMSMembers($data)
    {
        $sql = "
                SELECT
                    HEOD.EMP_CODE,
                    HEOD.DIVSN_ID,
                    HEOD.DEPT_ID,
                    HEOD.DESI_ID,
                    HEI.FNAME,
                    HEI.LNAME,
                    HEI.CELL,
                    HEI.STATUS,
                    HDIVSN.DIVSN_DESC,
                    HDEPT.DEPT_DESC,
                    HDESGN.DESI_DESC
                FROM HR_EMP_OFFICE_DET HEOD
                INNER JOIN HR_EMPLOYEE_INFO HEI
                    ON HEOD.EMP_CODE = HEI.EMP_CODE
                INNER JOIN HR_DIVISIONS HDIVSN
                    ON HEOD.DIVSN_ID = HDIVSN.DIVSN_ID
                INNER JOIN HR_DEPARTMENT HDEPT
                    ON HEOD.DEPT_ID = HDEPT.DEPT_ID
                INNER JOIN HR_DESIGNATION HDESGN
                    ON HEOD.DESI_ID = HDESGN.DESI_ID
                WHERE HEOD.DIVSN_ID = {$data['divsn_id']}
                AND HEOD.DEPT_ID = {$data['dept_id']}
                AND HEI.STATUS NOT IN ('R')
                ";

        $stmt = $this->execute($sql);

        return $this->fetchAll($stmt);
    }

    

    public function getHQDivision($data)
    {
        $topEmp="select emp_id, geo_id from SFM_DIV_TOPEMP_V where divsn_id='".$data['division_id']."' ";
        
        $stmt = $this->execute($topEmp);
        $topEmp = $this->fetchAll($stmt);
        
		$options = "select GEO_ID, (GEO_LABEL||' - '||GEO_DESC) GEONM from table(geoemp.list_down('".$topEmp[0]['EMP_ID']."','".$data['division_id']."',trunc(sysdate))) where geo_lvl=100 order by 2 ";
        $stmtOpt = $this->execute($options);
        
        return $this->fetchAll($stmtOpt);
    }

    public function getDivisionLabel($divId)
    {
        $sql = "select divsn_id, divsn_desc from sfm_divisions where divsn_id = '" . $divId . "' ";
        $stmt = $this->execute($sql);

        return $this->fetchAll($stmt);
    }

    public function getHQLabel($divId, $hqID)
    {
        $topEmp="select emp_id, geo_id from SFM_DIV_TOPEMP_V where divsn_id='" . $divId . "' ";
        
        $stmt = $this->execute($topEmp);
        $topEmp = $this->fetchAll($stmt);
        
		$options = "select GEO_ID, (GEO_LABEL||' - '||GEO_DESC) GEONM from table(geoemp.list_down('".$topEmp[0]['EMP_ID']."','" . $divId . "',trunc(sysdate))) where geo_lvl=100 and GEO_ID = '" . $hqID . "' ";
        $stmtOpt = $this->execute($options);

        return $this->fetchAll($stmtOpt);
    }

     public function fetchMembers($data)
    {
        $sql = "
            SELECT *
            FROM (
                SELECT
                    ngm.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY ngm.PARENT_ID
                        ORDER BY ngm.ID DESC
                    ) rn
                FROM NEW_GEO_MAPPING ngm
                WHERE ngm.GEO_ID = '" . $data['hq_id'] . "'
                AND ngm.DIVSN_ID = '" . $data['division_id'] . "'
            )
            WHERE rn = 1
        ";

        $stmt = $this->execute($sql);
        
        if (!$stmt) {
            echo "Statement Failed";
            exit;
        }
        $parentIds = $this->fetchAll($stmt);
        /*
        |--------------------------------------------------------------------------
        | EXTRACT PARENT IDS
        |--------------------------------------------------------------------------
        */
        $parentIdArray = array_column($parentIds, 'PARENT_ID');
        /*
        |--------------------------------------------------------------------------
        | ADD HQ_ID ALSO
        |--------------------------------------------------------------------------
        */
        $parentIdArray[] = $data['hq_id'];
        /*
        |--------------------------------------------------------------------------
        | REMOVE DUPLICATES (OPTIONAL)
        |--------------------------------------------------------------------------
        */
        $parentIdArray = array_unique($parentIdArray);
        /*
        |--------------------------------------------------------------------------
        | CONVERT TO COMMA STRING
        |--------------------------------------------------------------------------
        */
        $idString = implode(",", $parentIdArray);
        //echo $idString;

        $sql2 = "
            SELECT 
                DISTINCT dd.emp_id, 
                eg.geo_id,
                eg.geo_level,
                get_emp_code(eg.emp_id) EMPCODE, 
                (get_emp_nm(eg.emp_id) || ' (' || eg.emp_id || ')') NM 
            FROM (
                SELECT 
                    g.geo_id, 
                    g.geo_lvl,
                    g.divsn_id
                FROM new_geo_mapping g
                WHERE g.geo_lvl > 15
                AND g.divsn_id = '" . $data['division_id'] . "'
                AND TRUNC(SYSDATE) BETWEEN 
                    g.effec_from AND NVL(g.effec_to, '01-Jan-3000')
            ) x
            INNER JOIN sfm_emp_geo eg 
                ON eg.geo_level = x.geo_lvl 

            INNER JOIN sfm_emp_divisions dd 
                ON dd.divsn_id = x.divsn_id 
                AND eg.emp_id = dd.emp_id 

            WHERE TRUNC(SYSDATE) BETWEEN 
                dd.effec_from AND NVL(dd.effec_to, '01-Jan-3000')

            AND TRUNC(SYSDATE) BETWEEN 
                eg.effec_from AND NVL(eg.effec_to, '01-Jan-3000')  

            AND dd.divsn_id = '" . $data['division_id'] . "'

            AND eg.geo_id IN (" . $idString . ")

            ORDER BY eg.geo_id DESC
        ";
        $stmt1 = $this->execute($sql2);
        
        if (!$stmt1) {
            echo "Statement Failed";
            exit;
        }
        $finalMembersList = $this->fetchAll($stmt1);
        $geoEmpArr=array();
		foreach($finalMembersList as $emp)
		{
			$geoEmpArr[$emp['GEO_ID']]=array($emp['EMPCODE'], $emp['NM']);
		}

        // $geoEmpArr = [
        //     "14" => [
        //         ["05510", "Sunil Ghate", "919167655538"],
        //         ["00575", "SAMIR MOHITE", "919869248576"],
        //         ["01148", "BHALCHANDRA TAMANKAR", "919822209314"],
        //         ["04697", "POOJA VAIDYA", "918655083927"],
        //         ["04530", "PRATHMESH DARVES", "919664308686"],
        //         ["02617", "SANJAY MANKUMBARE", "919987209654"],
        //         ["05362", "SAVITA JAGTAP", "919967704468"],
        //         ["01948", "TANVI KOCHREKAR", "917977520772"],
        //     ]
        // ];
        // print_r($geoEmpArr); 
        // exit;

        return json_encode($geoEmpArr);
    }
}