<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class SyncOfflineData extends CI_Controller {

    public function __construct() {
        parent::__construct();
        // Load any necessary libraries or models here
        $this->load->helper('url');
        $this->load->library('form_validation');
    }

    public function index() {
        // Set the content type to JSON
        $this->output->set_content_type('application/json');

        // Prepare a response
        $response = [
            'status' => 'success',
            'message' => 'Data synced successfully',
            // Include other response data as needed
        ];

        // Send JSON response
        $this->output->set_output(json_encode($response));
    }
}
