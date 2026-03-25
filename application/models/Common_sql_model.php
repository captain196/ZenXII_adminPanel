<?php
class Common_sql_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
    }
    public function select_data($tbl_name, $field, $warr = '')
    {
        if ($warr != '') {
            $this->db->where($warr);
        }
        $res = $this->db->select($field)->from($tbl_name)->get();
        return $res->result_array();
    }

    // public function select_data($table, $columns = '*', $where = []) {
    //     $this->db->select($columns);
    //     $this->db->from($table);
    //     if (!empty($where)) {
    //         $this->db->where($where);
    //     }
    //     $query = $this->db->get();
    //     return $query->result_array();
    // }


    // public function insert_data($tbl_name, $data)
    // {
    //     $this->db->insert($tbl_name, $data);
    //     return $this->db->affected_rows();
    // }
    public function insert_data($table, $data)
    {
        $this->db->insert($table, $data);
        return $this->db->affected_rows();
    }

    public function insert_student($data)
    {
        $sql = "INSERT INTO `graderiq_school_management_system`.`student` 
                (`User Id`, `Name`, `Father Name`, `Mother Name`, `Email`, `DOB`, 
                `Phone Number`, `Gender`, `School Name`, `Class`, `Section`, `Address`, `Password`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $this->db->query($sql, $data);
        return $this->db->affected_rows();
    }

    public function update_data($tbl_name, $data, $where)
    {
        $this->db->where($where);
        $this->db->update($tbl_name, $data);
        return $this->db->affected_rows();
    }

    public function delete_data($tbl_name, $where)
    {
        $this->db->where($where);
        $this->db->delete($tbl_name);
        return $this->db->affected_rows();
    }

    // public function normalizeKeys($array) {
    //     $newArray = [];
    //     foreach ($array as $key => $value) {
    //         // Replace underscores with spaces
    //         $newKey = str_replace('_', ' ', $key);
    //         $newArray[$newKey] = $value;
    //     }
    //     return $newArray;
    // }


}
