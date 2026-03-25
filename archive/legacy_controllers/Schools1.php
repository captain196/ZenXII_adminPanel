<?php



defined('BASEPATH') or exit('No direct script access allowed');

class Schools extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function fees()
    {
        $this->load->view('include/header');
        $this->load->view('fees');
        $this->load->view('include/footer');
    }
    public function delete_school($schoolId)
    {
        // Fetch school name from School_ids using $schoolId
        $schoolName = $this->CM->get_school_name_by_id($schoolId);

        if ($schoolName) {

            // Delete the school data from Schools
            $result1 = $this->CM->delete_data('Schools', $schoolName);
            // Delete the school data from Schools
            $result2 = $this->CM->delete_data('School_ids', $schoolId);

            // Delete the school data from Users
            // $result2 = $this->CM->delete_data("Users/Schools/", $schoolName);


            // Delete the school's entire folder from Firebase Storage
            $deleteStorageResult = $this->CM->delete_folder_from_firebase_storage($schoolName . '/');

            // Check if all deletions were successful
            if ($result1 && $result2 && $deleteStorageResult) {

                // Fetch the current count from School_ids
                $currentSchoolCount = $this->CM->get_data('School_ids/Count');

                // Decrement the count by 1
                $newSchoolCount = $currentSchoolCount - 1;

                // Update the count in Firebase
                $this->CM->addKey_pair_data('School_ids/', ['Count' => $newSchoolCount]);

                // Redirect to manage_school page with success message
                redirect('schools/manage_school');
            } else {
                // Redirect to manage_school page with error message
                // $this->session->set_flashdata('message', 'Failed to delete school.');
                redirect('schools/manage_school');
            }
        } else {
            // Redirect to manage_school page with error message if schoolName is not found
            // $this->session->set_flashdata('message', 'School not found.');
            redirect('schools/manage_school');
        }
    }



    public function edit_school($schoolId)
    {
        $session_year = $this->session_year;
        $schoolDetails = $this->CM->get_school_name_by_id($schoolId); // Get school id inside the variable schoolDetails

        if (!is_array($schoolDetails)) {
            $schoolDetails = [
                'School Id' => $schoolId,
                'School Name' => $schoolDetails
            ];
        }

        if ($this->input->method() == 'post') {
            $postData = $this->input->post();
            $normalizedData = $this->CM->normalizeKeys($postData); // Normalize the keys of the post data
            $newSchoolName = $normalizedData['School Name'];
            $oldSchoolName = isset($schoolDetails['School Name']) ? $schoolDetails['School Name'] : null;

            $oldFolderPath = $oldSchoolName . '/';
            $newFolderPath = $newSchoolName . '/';

            $files = [
                'school_logos' => $_FILES['school_logos'],
                'holidays' => $_FILES['holidays'],
                'academic' => $_FILES['academic']
            ];

            $changeSchoolName = $oldSchoolName && $oldSchoolName !== $newSchoolName;
            $updateFiles = !empty(array_filter($files, fn($file) => isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])));

            $updatedFiles = $this->CM->update_files_and_folder_in_firebase_storage($oldFolderPath, $newFolderPath, $files, $changeSchoolName, $updateFiles);

            if ($updatedFiles === false) {
                echo '0';
                return;
            }

            // Retrieve existing data from Firebase
            $existingData = $this->CM->get_data('Schools/' . $oldSchoolName . '/' . $session_year);
            // Prepare data to update in Firebase
            $dataToUpdate = $existingData ?: [];

            if (isset($updatedFiles['school_logos'])) {
                $dataToUpdate['Logo'] = $updatedFiles['school_logos'];
            }

            if (isset($updatedFiles['holidays'])) {
                $dataToUpdate['Holidays'] = $updatedFiles['holidays'];
            }

            if (isset($updatedFiles['academic'])) {
                $dataToUpdate['Academic calendar'] = $updatedFiles['academic'];
            }

            if ($changeSchoolName) {
                if ($existingData) {
                    // Update data in Schools with new school name
                    $res1 = $this->CM->update_data('Schools/' . $newSchoolName . '/' . $session_year, null, $dataToUpdate);

                    if ($res1) {
                        // Delete old school data
                        $this->CM->delete_data('Schools/', $oldSchoolName . '/' . $session_year);
                        // Update school ID mapping
                        $res2 = $this->CM->update_data('', 'School_ids/', [$schoolId => $newSchoolName]);

                        if (!$res2) {
                            echo '0';
                            return;
                        }
                    } else {
                        echo '0';
                        return;
                    }
                } else {
                    echo '0';
                    return;
                }
            } else {
                // Update the data in Firebase without changing the school name
                $this->CM->update_data('Schools/' . $newSchoolName . '/' . $session_year, null, $dataToUpdate);
            }

            // Retrieve existing data from Firebase under Users->Schools->SchoolName
            $userData = $this->CM->select_data('Users/Schools/' . $oldSchoolName);

            // Prepare data to update in Users->Schools->SchoolName
            $userDataToUpdate = $userData ?: [];

            // Update all form fields if changed
            foreach ($normalizedData as $key => $value) {
                if ($key !== 'School Name' && $key !== 'school_logos' && $key !== 'holidays' && $key !== 'academic') {
                    $userDataToUpdate[$key] = $value;
                }
            }

            // Update logo URL if changed
            if (isset($updatedFiles['school_logos'])) {
                $userDataToUpdate['Logo'] = $updatedFiles['school_logos'];
            }
            $userDataToUpdate['School Name'] = $newSchoolName;

            // If school name has changed, delete old data under the previous school name in Users
            if ($changeSchoolName) {
                // Update data in Users->Schools->SchoolName with new school name
                $this->CM->update_data('Users/Schools/' . $newSchoolName, null, $userDataToUpdate);
                $this->CM->delete_data('Users/Schools/', $oldSchoolName);
            } else {
                // Update data in Users->Schools->SchoolName with old school name
                $this->CM->update_data('Users/Schools/' . $oldSchoolName, null, $userDataToUpdate);
            }

            echo '1';
        } else {
            $data['school'] = $schoolDetails;
            echo '<pre>' . print_r($data, true) . '</pre>';

            // Fetch data from Users->Schools->Schoolname
            if (!empty($schoolDetails['School Name'])) {
                $userSchoolData = $this->CM->select_data('Users/Schools/' . $schoolDetails['School Name']);
                // echo '<pre>' . print_r($userSchoolData, true) . '</pre>';


                // Check if user school data exists and set it to the view data
                if ($userSchoolData) {
                    $data['schooll'] = $userSchoolData;
                }

                // Get file URLs for school logo, holidays, and academic calendar
                $data['school_logo_url'] = $this->CM->get_file_url($schoolDetails['School Name'] . '/school_logos/school_logos.jpg');
                $data['holidays_url'] = $this->CM->get_file_url($schoolDetails['School Name'] . '/holidays/holidays');
                $data['academic_url'] = $this->CM->get_file_url($schoolDetails['School Name'] . '/academic/academic');
            } else {
                $data['school_logo_url'] = '';
                $data['holidays_url'] = '';
                $data['academic_url'] = '';
            }

            // Load the edit school view with the fetched data
            $this->load->view('include/header');
            $this->load->view('edit_school', $data);
            $this->load->view('include/footer');
        }
    }

    public function schoolProfile()
    {

        $school_name = $this->school_name;

        // Fetching data from the Firebase nested path
        $schoolData = $this->firebase->get('Users/Schools/' . $school_name);

        // Default fallback if data isn't found
        if (!$schoolData) {
            $schoolData = [];
        }

        // Retrieve Start Date and Subscription End Date with default values if not available
        $startDate = $schoolData['subscription']['duration']['startDate'] ?? null; // Updated key path
        $endDate = $schoolData['subscription']['duration']['endDate'] ?? null; // Updated key path

        // Convert the dates to Unix timestamps safely
        $startDateTimestamp = $startDate ? strtotime($startDate) : null;
        $endDateTimestamp = $endDate ? strtotime($endDate) : null;

        // Calculate the number of days left if valid dates are provided
        $daysLeft = null;
        if ($endDateTimestamp) {
            $currentDateTimestamp = time(); // Current timestamp
            $daysLeft = ceil(($endDateTimestamp - $currentDateTimestamp) / (60 * 60 * 24)); // Calculate the days left

            // If the end date has passed, set the days left to 0
            if ($daysLeft < 0) {
                $daysLeft = 0;
            }
        }

        // Pass the school data and days left to the view
        $data['schoolData'] = $schoolData;
        $data['daysLeft'] = $daysLeft;

        // Load views
        $this->load->view('include/header');
        $this->load->view('schoolprofile', $data);
        $this->load->view('include/footer');
    }


    public function manage_school()
    {
        if ($this->input->method() == 'post') {
            // Get form data
            $formData = $this->input->post();


            // Normalize keys in form data
            $normalizedFormData = $this->CM->normalizeKeys($formData);
            // echo '<pre>' . print_r($normalizedFormData, true) . '</pre>';


            // Check if school_name key exists
            if (isset($normalizedFormData['School Name'])) {

                // Initialize array to store file URLs
                $fileUrls = [];
                $userFileUrls = [];

                // Handle file uploads and merge their URLs into the form data
                if (!empty($_FILES['school_logo']['name'])) {
                    $logoUrl = $this->CM->handleFileUpload($_FILES['school_logo'], $normalizedFormData['School Name'], 'school_logos', 'school_logos', true);
                    $fileUrls['Logo'] = $logoUrl ?: 'No logo';
                    $userFileUrls['Logo'] = $logoUrl ?: 'No logo';
                }

                if (!empty($_FILES['Holidays']['name'])) {
                    $holidaysUrl = $this->CM->handleFileUpload($_FILES['Holidays'], $normalizedFormData['School Name'], 'holidays', 'holidays', true);
                    $fileUrls['Holidays'] = $holidaysUrl;
                }

                if (!empty($_FILES['Academic']['name'])) {
                    $academicUrl = $this->CM->handleFileUpload($_FILES['Academic'], $normalizedFormData['School Name'], 'academic', 'academic', true);
                    $fileUrls['Academic calendar'] = $academicUrl;
                }

                $subscriptionData = [
                    'planName' => $normalizedFormData['subscription plan'],
                    'amount' => [
                        'totalAmount' => $normalizedFormData['last payment amount'],
                        'monthly' => $normalizedFormData['last payment amount'] / $normalizedFormData['subscription duration']
                    ],
                    'duration' => [
                        'periodInMonths' => $normalizedFormData['subscription duration'],
                        'startDate' => date('Y-m-d'), // Assuming today
                        'endDate' => date('Y-m-d', strtotime("+{$normalizedFormData['subscription duration']} months"))
                    ],
                    'status' => 'Active',
                    'features' => $normalizedFormData['features']

                ];

                $paymentData = [
                    'lastPaymentAmount' => $normalizedFormData['last payment amount'],
                    'lastPaymentDate' => $normalizedFormData['last payment date'],
                    'paymentMethod' => $normalizedFormData['payment method']
                ];

                // Remove specific keys from normalizedFormData
                $keysToRemove = ['last payment amount', 'last payment date', 'payment method', 'subscription duration', 'subscription plan', 'features'];
                foreach ($keysToRemove as $key) {
                    if (isset($normalizedFormData[$key])) {
                        unset($normalizedFormData[$key]);
                    }
                }

                // Merge logo URL and file URLs into normalized form data for Users/Schools/SchoolName
                $finalFormData = array_merge($normalizedFormData, $userFileUrls, ['subscription' => $subscriptionData], ['payment' => $paymentData]);

                // Prepare data to insert into Firebase under Users/Schools/SchoolName
                $schoolName = $finalFormData['School Name'];
                $dataToInsertUsers = [$schoolName => $finalFormData];

                // Insert all form data into Firebase under Users->Schools->SchoolName
                $resultUsers = $this->CM->addKey_pair_data('Users/Schools/', $dataToInsertUsers);

                // Default values for Schools/SchoolName
                $defaultValues = [
                    'Activities' => [
                        '1' => 'https://firebasestorage.googleapis.com/v0/b/graders-1c047.appspot.com/o/Maharishi%20Vidhya%20Mandir%2C%20Balaghat%2Factivities%2Factivity_5.png?alt=media&token=5b97b8b2-ebfd-4cf8-80e6-7066935d9a8f',
                        '2' => 'https://firebasestorage.googleapis.com/v0/b/graders-1c047.appspot.com/o/Maharishi%20Vidhya%20Mandir%2C%20Balaghat%2Factivities%2Factivity_2.jpg?alt=media&token=bfa69104-fc82-4e3b-a65b-97cc6b3fb43e',
                        '3' => 'https://firebasestorage.googleapis.com/v0/b/graders-1c047.appspot.com/o/Maharishi%20Vidhya%20Mandir%2C%20Balaghat%2Factivities%2Factivity_4.jpg?alt=media&token=718a6c9e-ffde-4c05-a591-d30c59348f89',
                        '4' => 'https://firebasestorage.googleapis.com/v0/b/graders-1c047.appspot.com/o/Maharishi%20Vidhya%20Mandir%2C%20Balaghat%2Factivities%2Factivity_3.jpg?alt=media&token=89eeb8d6-b482-40ab-a172-57952e1b2856'
                    ],
                    'Features' => [
                        'Assignment' => '',
                        'Attendance' => '',
                        'Notification' => '',
                        'Profile' => '',
                        'Syllabus' => '',
                        'Time Table' => ''
                    ],
                    'Total Classes' => ['Classes Done' => '', 'Total' => '']
                ];

                // Combine file URLs with default values for Schools/SchoolName
                $schoolDataToInsert = array_merge($defaultValues, $fileUrls);

                // Generate session like "2025-26"
                $currentYear = date('Y');
                $nextYear = date('y', strtotime('+1 year'));
                $session_year = "$currentYear-$nextYear";

                // Insert combined data into Firebase under Schools->SchoolName
                // $result2 = $this->CM->addKey_pair_data('Schools/' . $schoolName . '/', $schoolDataToInsert);
                $result2 = $this->CM->addKey_pair_data("Schools/$schoolName/$session_year/", $schoolDataToInsert);


                // Fetch the current count from Firebase for students
                $currentSchoolCount = $this->CM->get_data('School_ids/Count');
                // $newSchoolId = $currentSchoolCount;
                $newSchoolId = 'SCH' . str_pad($currentSchoolCount, 4, '0', STR_PAD_LEFT);

                // Insert data into Firebase for school ID
                $result1 = $this->CM->addKey_pair_data('School_ids/', [$newSchoolId => $schoolName]);

                // Increment the Count in Firebase after successful insertion
                if ($resultUsers && $result1 && $result2) {

                    // Add Session key under Schools/SchoolName
                    $this->CM->addKey_pair_data("Schools/$schoolName/", ['Session' => $session_year]);

                    $newSchoolCount = $currentSchoolCount + 1;
                    $this->CM->addKey_pair_data('School_ids/', ['Count' => $newSchoolCount]);
                    echo '1'; // Echo 1 if data is inserted successfully
                } else {
                    echo '0'; // Echo 0 if data is not inserted into Schools
                }
            } else {
                // Handle case where school_name is missing
                echo 'School name is missing';
            }
        } else {
            // Handle GET requests (rendering the page)
            $currentSchoolCount = $this->CM->get_data('School_ids/Count');
            $schoolIds = $this->CM->select_data('School_ids');
            $schools = [];

            foreach ($schoolIds as $schoolId => $schoolName) {
                if ($schoolId === 'Count') {
                    continue;
                }

                // Retrieve the school data
                $schoolData = $this->CM->select_data('Users/Schools/' . $schoolName);

                if ($schoolData) {
                    $schoolData['School Id'] = $schoolId;
                    $schoolData['School Name'] = $schoolName;

                    // Validate logo URL from Firebase Storage, or set to 'No logo' if not found
                    $logoPath = $schoolName . '/school_logos/school_logos.jpg';
                    $logoUrl = $this->CM->get_file_url($logoPath);
                    $schoolData['Logo'] = $logoUrl ?: 'No logo';

                    $schools[] = $schoolData;
                }
            }

            $data['Schools'] = $schools;
            $data['currentSchoolCount'] = $currentSchoolCount; // Pass current count to the view

            $this->load->view('include/header');
            $this->load->view('manage_school', $data);
            $this->load->view('include/footer');
        }
    }


    public function schoolgallery()
    {

        $this->load->view('include/header');
        $this->load->view('schoolgallery');
        $this->load->view('include/footer');
    }

    public function fetchGalleryMedia()
    {

        header('Content-Type: application/json');


        $school_name = $this->school_name;
        $session_year = $this->session_year;

        $dbPath = "/Schools/$school_name/$session_year/Gallery/";

        // Fetch from Firebase
        $galleryData = $this->firebase->get($dbPath);
        if (!$galleryData) {
            echo json_encode(['images' => [], 'videos' => [], 'debug' => 'No data or Firebase error']);
            return;
        }

        $images = [];
        $videos = [];

        foreach ($galleryData as $key => $media) {
            if (isset($media['image']) && isset($media['type'])) {
                $mediaItem = [
                    'url' => $media['image'],
                    'timestamp' => $media['Time_stamp'] ?? 0,
                ];

                if ($media['type'] == "1") {
                    $images[] = $mediaItem;
                } elseif ($media['type'] == "2") {
                    $mediaItem['thumbnail'] = $media['thumbnail'] ?? '';
                    $mediaItem['duration'] = $media['duration'] ?? '';
                    $videos[] = $mediaItem;
                }
            }
        }

        echo json_encode(['images' => $images, 'videos' => $videos]);
    }


    
    public function deleteMedia()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        $fileUrl = $this->input->get('url');

        if (!$fileUrl) {
            echo json_encode(["status" => "error", "message" => "File URL is required"]);
            return;
        }

        try {
            // Extract the storage path from the full URL
            $filePath = $this->extract_firebase_storage_path($fileUrl);
            if (!$filePath) {
                echo json_encode(["status" => "error", "message" => "Invalid file path extracted"]);
                return;
            }

            // Delete file from Firebase Storage
            $deleteStorage = $this->CM->delete_file_from_firebase($filePath);
            if (!$deleteStorage) {
                echo json_encode(["status" => "error", "message" => "Failed to delete file from Storage"]);
                return;
            }

            // Firebase Database path
            $galleryRef = "/Schools/$school_name/$session_year/Gallery";
            $galleryData = $this->firebase->get($galleryRef);

            if ($galleryData && is_array($galleryData)) {
                foreach ($galleryData as $key => $media) {
                    if (isset($media['image']) && trim($media['image']) == trim($fileUrl)) {
                        // Also delete thumbnail if exists
                        if (isset($media['thumbnail'])) {
                            $thumbPath = $this->extract_firebase_storage_path($media['thumbnail']);
                            if ($thumbPath) {
                                $this->CM->delete_file_from_firebase($thumbPath);
                            }
                        }

                        // Delete the node from Realtime Database
                        $this->firebase->delete("$galleryRef/$key");

                        echo json_encode(["status" => "success", "message" => "File deleted successfully"]);
                        return;
                    }
                }
            }

            echo json_encode(["status" => "error", "message" => "File not found in Database"]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }



    private function extract_firebase_storage_path($url)
    {
        // Parse query string to isolate the file path
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['path'])) {
            return null;
        }

        // Example: /v0/b/graders-1c047.appspot.com/o/schoolName%2FsessionYear%2FGallery%2Ffile.mp4
        $path = $parsedUrl['path'];

        // Remove the prefix up to `/o/`
        $pos = strpos($path, '/o/');
        if ($pos === false) {
            return null;
        }

        $encodedPath = substr($path, $pos + 3); // Skip "/o/"
        $decodedPath = urldecode($encodedPath); // Decode %2F to /

        return $decodedPath;
    }



    public function uploadMedia()
    {
        $school_name = $this->school_name;
        $session_year = $this->session_year;

        if (!isset($_FILES['file'])) {
            echo json_encode(['status' => 'error', 'message' => 'No file uploaded']);
            return;
        }

        $file = $_FILES['file'];
        $fileName = $file['name'];
        $fileTmpPath = $file['tmp_name'];
        $fileSize = $file['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $fileType = $this->input->post('type'); // "1" for image, "2" for video

        $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        $allowedVideoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
        $maxImageSize = 5 * 1024 * 1024;
        $maxVideoSize = 50 * 1024 * 1024;

        if ($fileType == "1" && (!in_array($fileExtension, $allowedImageExtensions) || $fileSize > $maxImageSize)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid image format or size exceeded']);
            return;
        }

        if ($fileType == "2" && (!in_array($fileExtension, $allowedVideoExtensions) || $fileSize > $maxVideoSize)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid video format or size exceeded']);
            return;
        }

        $timestamp = time();
        $randomString = substr(md5(uniqid(mt_rand(), true)), 0, 6);

        $dbPath = "/Schools/$school_name/$session_year/Gallery/";
        $storagePath = "$school_name/$session_year/Gallery/";
        $newFileName = ($fileType == "1" ? "img_" : "vid_") . "{$timestamp}_{$randomString}.{$fileExtension}";
        $firebaseStoragePath = $storagePath . $newFileName;

        // Upload file to Firebase Storage
        $uploadResult = $this->firebase->uploadFile($fileTmpPath, $firebaseStoragePath);
        if ($uploadResult !== true) {
            echo json_encode(['status' => 'error', 'message' => $uploadResult]);
            return;
        }

        $downloadUrl = $this->firebase->getDownloadUrl($firebaseStoragePath);

        $mediaData = [
            'Time_stamp' => $timestamp,
            'image' => $downloadUrl,
            'type' => $fileType
        ];

        // 👉 Handle video-specific logic
        if ($fileType == "2") {
            $ffmpeg = "C:\\ffmpeg\\bin\\ffmpeg.exe";
            $ffprobe = "C:\\ffmpeg\\bin\\ffprobe.exe";

            // 1. Get Duration
            $durationCommand = "\"$ffprobe\" -v error -select_streams v:0 -show_entries stream=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($fileTmpPath);
            $durationOutput = shell_exec($durationCommand);
            $durationRaw = trim($durationOutput);
            $durationSeconds = is_numeric($durationRaw) ? round(floatval($durationRaw), 2) : 0;
            
            $minutes = floor($durationSeconds / 60);
            $seconds = round($durationSeconds - ($minutes * 60));



            if ($seconds == 60) {
                $minutes += 1;
                $seconds = 0;
            }

            $mediaData['duration'] = sprintf("%d:%02d", $minutes, $seconds);

            log_message('debug', 'Video duration parsed: ' . $mediaData['duration']);
            log_message('debug', 'Duration in seconds: ' . $durationSeconds);

            // 2. Generate Thumbnail
            $thumbnailName = "thumb_{$timestamp}_{$randomString}.jpg";
            $thumbnailPathLocal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $thumbnailName;
            $thumbnailCommand = "\"$ffmpeg\" -i " . escapeshellarg($fileTmpPath) . " -ss 00:00:01.000 -vframes 1 -q:v 2 " . escapeshellarg($thumbnailPathLocal);
            shell_exec($thumbnailCommand);

            // 3. Upload Thumbnail
            $thumbnailStoragePath = "$school_name/$session_year/Gallery/thumbnails/" . $thumbnailName;
            $this->firebase->uploadFile($thumbnailPathLocal, $thumbnailStoragePath);
            $thumbnailUrl = $this->firebase->getDownloadUrl($thumbnailStoragePath);
            $mediaData['thumbnail'] = $thumbnailUrl;

            log_message('debug', 'Thumbnail uploaded: ' . $thumbnailUrl);

            // Delete local temp thumbnail
            @unlink($thumbnailPathLocal);
        }

        // Store metadata to Realtime Database
        $this->firebase->push($dbPath, $mediaData);

        log_message('debug', 'Media uploaded: ' . json_encode($mediaData));

        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'File uploaded successfully',
            'mediaData' => $mediaData // 🔁 send this back to JS
        ]);
    }
}
