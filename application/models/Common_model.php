<?php
require 'vendor/autoload.php';  // Ensure Composer's autoload file is included

use Google\Cloud\Storage\StorageClient;


class Common_model extends CI_Model
{
    private $firebaseDatabase;
    private $firebaseStorage;

    public function __construct()
    {
        parent::__construct();

        // ── C-07 FIX: Reuse the Firebase library singleton instead of creating a
        //    duplicate SDK instance. Halves memory usage and connection count. ──
        $CI =& get_instance();
        if (isset($CI->firebase) && is_object($CI->firebase)) {
            // Reuse existing Firebase library's database instance
            $this->firebaseDatabase = $CI->firebase->getDatabase();
            // Reuse storage bucket from Firebase library
            $this->firebaseStorage = $CI->firebase->getStorageBucket();
        } else {
            // Fallback: initialize independently (e.g. CLI context without MY_Controller)
            $serviceAccountPath = __DIR__ . '/../config/graders-1c047-firebase-adminsdk-z1a10-ca28a54060.json';
            $databaseUri = 'https://graders-1c047-default-rtdb.asia-southeast1.firebasedatabase.app/';

            $firebase = (new \Kreait\Firebase\Factory)
                ->withServiceAccount($serviceAccountPath)
                ->withDatabaseUri($databaseUri);

            $this->firebaseDatabase = $firebase->createDatabase();
            $this->initializeFirebaseStorage($serviceAccountPath);
        }
    }

    private function initializeFirebaseStorage($serviceAccountPath)
    {
        $storage = new StorageClient([
            'keyFilePath' => $serviceAccountPath
        ]);
        $this->firebaseStorage = $storage->bucket('graders-1c047.appspot.com');
    }



    // public function handleFileUpload($file, $schoolName, $folderName, $file_name, $useDefaultFileName = false) {
    //     if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    //         return null; // Return null if the file is not provided
    //     }

    //     // Determine the filename
    //     $fileName = $useDefaultFileName ? $file_name . '.jpg' : basename($file['name']);

    //     // Construct the Firebase Storage path
    //     $firebaseStoragePath = $schoolName . '/' . $folderName . '/' . $fileName;

    //     // Upload the file to Firebase Storage
    //     $uploadResult = $this->upload_to_firebase_storage($file['tmp_name'], $firebaseStoragePath);

    //     return $uploadResult ? $uploadResult['mediaLink'] : false;
    // }



    // Function to rename a folder in Firebase Storage
    // public function upload_to_firebase_storage($filePath, $firebaseStoragePath) {

    //     if (empty($filePath)) {
    //         return false; // Return false or null if no file path is provided
    //     }

    //     try {
    //         $object = $this->firebaseStorage->upload(fopen($filePath, 'r'), [
    //             'name' => $firebaseStoragePath
    //         ]);

    //         $url = $object->signedUrl(
    //             new \DateTime('tomorrow'), // Expires in 1 day
    //             [
    //                 'version' => 'v4',
    //             ]
    //         );

    //         return ['mediaLink' => $url];
    //     } catch (\Exception $e) {
    //         log_message('error', 'Failed to upload file to Firebase Storage: ' . $e->getMessage());
    //         return false;
    //     }
    // }


    public function delete_folder_from_firebase_storage($folderPath)
    {
        try {
             if (empty($folderPath)) {
            log_message('error', 'Empty folder path passed to delete_folder_from_firebase_storage');
            return false;
        }
            // Get all objects (files) within the specified folder path
            // $objects = $this->firebaseStorage->objects([
            //     'prefix' => $folderPath
            // ]);
            $objects = $this->firebaseStorage->objects([
                'prefix' => rtrim($folderPath, '/') . '/'
            ]);


            // $allDeleted = true;
            $deletedAny = false;

            // Iterate through each object and delete it
            foreach ($objects as $object) {
                if ($object->exists()) {
                    $object->delete();
                    $deletedAny = true;
                } 
            }
            return $deletedAny;

            // return $allDeleted;
        } catch (\Exception $e) {
            log_message('error', 'Failed to delete folder from Firebase Storage: ' . $e->getMessage());
            return false;
        }
    }


    // public function update_files_and_folder_in_firebase_storage($oldFolderPath, $newFolderPath, $files, $changeSchoolName = false, $updateFiles = false) {
    //     try {
    //         $updatedFiles = [];

    //         // Change the folder name if required
    //         if ($changeSchoolName) {
    //             // Rename folder in Firebase Storage
    //             $objects = $this->firebaseStorage->objects(['prefix' => $oldFolderPath]);

    //             foreach ($objects as $object) {
    //                 $oldObjectName = $object->name();
    //                 $newObjectName = str_replace($oldFolderPath, $newFolderPath, $oldObjectName);

    //                 // Copy the object to the new location
    //                 $object->copy($this->firebaseStorage, ['name' => $newObjectName]);
    //                 log_message('debug', "Copied object from $oldObjectName to $newObjectName");

    //                 // Delete the old object
    //                 $object->delete();
    //                 log_message('debug', "Deleted old object at $oldObjectName");
    //             }
    //         }

    //         // Update files in Firebase Storage if required
    //         if ($updateFiles) {
    //             foreach ($files as $type => $file) {
    //                 try {
    //                     if (isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
    //                         $newFilePath = $file['tmp_name'];
    //                         // Set the file name to the folder name (type) with the .jpg extension
    //                         $fileName = $type . '.jpg';
    //                         $newFirebasePath = ($changeSchoolName ? $newFolderPath : $oldFolderPath) . $type . '/' . $fileName;

    //                         // Upload the new file
    //                         $newObject = $this->firebaseStorage->upload(fopen($newFilePath, 'r'), [
    //                             'name' => $newFirebasePath
    //                         ]);

    //                         $url = $newObject->signedUrl(new \DateTime('tomorrow'), ['version' => 'v4']);
    //                         log_message('debug', "Uploaded new file to path: $newFirebasePath with URL: $url");
    //                         $updatedFiles[$type] = $url;
    //                     } else {
    //                         log_message('debug', "No file uploaded for type: $type");
    //                     }
    //                 } catch (\Exception $e) {
    //                     log_message('error', "Failed to upload file for type $type: " . $e->getMessage());
    //                 }
    //             }
    //         }

    //         return $updatedFiles;
    //     } catch (\Exception $e) {
    //         log_message('error', 'Failed to update files and folder in Firebase Storage: ' . $e->getMessage());
    //         return false;
    //     }
    // }

    // public function get_file_url($firebaseStoragePath) {
    //     try {
    //         $object = $this->firebaseStorage->object($firebaseStoragePath);
    //         if ($object->exists()) {
    //             $url = $object->signedUrl(new \DateTime('tomorrow'), ['version' => 'v4']);
    //             return $url;
    //         } else {
    //             return '';
    //         }
    //     } catch (\Exception $e) {
    //         log_message('error', 'Failed to get file URL from Firebase Storage: ' . $e->getMessage());
    //         return '';
    //     }
    // }

    public function insert_data($parentNode, $data)
    {
        try {
            // Format keys to replace encoded characters with spaces
            $formattedData = [];
            foreach ($data as $key => $value) {
                $formattedKey = str_replace(['%20', '_'], ' ', $key);
                $formattedData[$formattedKey] = $value;
            }

            // Check if $data is an associative array
            if (!is_array($formattedData) || empty($formattedData)) {
                throw new Exception('Invalid data format. Expected a non-empty associative array.');
            }

            // Extract the user ID from the data array
            if (!isset($formattedData['User Id'])) {
                throw new Exception('User Id is missing from the data array.');
            }
            $userId = $formattedData['User Id'];

            // Set the data at the specified parent node with the user ID as the key
            $this->firebaseDatabase->getReference($parentNode . '/' . $userId)->set($formattedData);

            return true;
        } catch (FirebaseException $e) {
            log_message('error', 'Firebase Insert Error: ' . $e->getMessage());
            return false;
        } catch (Exception $e) {
            log_message('error', 'General Error: ' . $e->getMessage());
            return false;
        }
    }

    public function get_school_name_by_id($schoolId)
    {
        try {
            return $this->firebaseDatabase->getReference('Indexes/School_codes/' . $schoolId)->getValue();
        } catch (Exception $e) {
            log_message('error', 'Failed to get school name by ID: ' . $e->getMessage());
            return null;
        }
    }

    public function select_data($parentNode, $fields = [], $conditions = [])
    {
        try {
            $nodeReference = $this->firebaseDatabase->getReference($parentNode);

            if (!empty($conditions)) {
                foreach ($conditions as $key => $value) {
                    $nodeReference = $nodeReference->orderByChild($key)->equalTo($value);
                }
            }

            $snapshot = $nodeReference->getSnapshot();
            $data = $snapshot->getValue();

            if ($data === null) {
                return [];
            }

            // Filter data if specific fields are requested
            if ($fields) {
                $filteredResult = [];
                foreach ($data as $key => $item) {
                    if (is_array($item)) {
                        $filteredItem = array_intersect_key($item, array_flip((array) $fields));
                        // Ensure the filtered item is not empty before adding it
                        if (!empty($filteredItem)) {
                            $filteredResult[$key] = $filteredItem;
                        }
                    }
                }
                return $filteredResult;
            }

            // Ensure no empty items are included
            $nonEmptyData = array_filter($data, function ($item) {
                return !empty($item);
            });

            return $nonEmptyData;
        } catch (Exception $e) {
            log_message('error', 'Firebase Select Error: ' . $e->getMessage());
            return [];
        }
    }

    public function delete_data($parentNode, $id)
    {
        try {
            // Create the reference to the node to delete
            $nodeReference = $this->firebaseDatabase->getReference($parentNode . '/' . $id);
            // Remove the node
            $nodeReference->remove();
            return true;
        } catch (Exception $e) {
            // Handle the error appropriately
            log_message('error', 'Firebase Delete Error: ' . $e->getMessage());
            return false;
        }
    }

    public function get_data($path)
    {
        $reference = $this->firebaseDatabase->getReference($path);
        return $reference->getValue();
    }

    public function update_data($parentNode, $id, $data)
    {
        try {
            // Create the reference to the node to update
            $nodeReference = $this->firebaseDatabase->getReference($parentNode . '/' . $id);
            // Update the node with the new data
            $nodeReference->update($data);
            return true;
        } catch (Exception $e) {
            // Handle the error appropriately
            log_message('error', 'Firebase Update Error: ' . $e->getMessage());
            return false;
        }
    }

    public function addKey_pair_data($parentNode, $data)
    {
        try {
            // Create the reference to the node
            $nodeReference = $this->firebaseDatabase->getReference($parentNode);

            log_message('debug', 'Firebase Reference: ' . $parentNode);
            log_message('debug', 'Data to be updated: ' . json_encode($data));
            // Update the node with the new data
            $nodeReference->update($data);
            return true;
        } catch (Exception $e) {
            // Handle the error appropriately
            log_message('error', 'Firebase Update Error: ' . $e->getMessage());
            return false;
        }
    }

    function normalizeKeys($array)
    {
        $newArray = [];
        foreach ($array as $key => $value) {
            // Replace underscores with spaces
            $newKey = (str_replace('_', ' ', $key));
            // $newKey = str_replace('_', ' ', $key);
            $newArray[$newKey] = $value;
        }
        return $newArray;
    }


    public function checkIfSchoolFolderExists($schoolName)
    {
        try {
            // Check if any object exists under the school folder prefix in Firebase Storage
            $objects = $this->firebaseStorage->objects(['prefix' => $schoolName . '/']);

            // If there are any objects under the school path, the school folder exists
            return !empty($objects);
        } catch (\Exception $e) {
            log_message('error', 'Failed to check if school folder exists: ' . $e->getMessage());
            return false; // Return false if there is an error during the check
        }
    }

    function formatAndPrepareFirebaseData($postData)
    {
        $formattedData = [];

        foreach ($postData as $key => $value) {
            if ($value !== '') {
                // Replace underscores with spaces, capitalize each word
                $formattedKey = ucwords(str_replace('_', ' ', $key));
                $formattedData[$formattedKey] = $value;
            }
        }

        return $formattedData;
    }

    public function handleFileUpload($file, $schoolName, $folderName, $file_name, $useDefaultFileName = false, $updateExisting = false)
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return null; // Return null if no file is provided
        }

        // Extract the correct file extension
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Ensure correct filename
        $fileName = $useDefaultFileName ? $file_name . '.' . $fileExtension : basename($file['name']);

        // Construct the Firebase Storage path
        $firebaseStoragePath = $schoolName . '/' . $folderName . '/' . $fileName;

        // *Check if updating an existing file*
        if ($updateExisting) {
            $existingUrl = $this->get_file_url($firebaseStoragePath);
            if (!empty($existingUrl)) {
                $this->delete_file_from_firebase($firebaseStoragePath); // Delete old file
            }
        }

        // Upload the file to Firebase Storage
        $uploadResult = $this->upload_to_firebase_storage($file['tmp_name'], $firebaseStoragePath);

        return $uploadResult ? $uploadResult['mediaLink'] : false;
    }

    public function upload_to_firebase_storage($filePath, $firebaseStoragePath)
    {
        if (empty($filePath)) {
            return false; // Return false if no file path is provided
        }

        try {
            // Generate a unique download token
            $downloadToken = bin2hex(random_bytes(16));

            // *Delete existing file before uploading a new one*
            $existingUrl = $this->get_file_url($firebaseStoragePath);
            if (!empty($existingUrl)) {
                $this->delete_file_from_firebase($firebaseStoragePath);
            }

            // Upload the file to Firebase Storage
            $object = $this->firebaseStorage->upload(fopen($filePath, 'r'), [
                'name' => $firebaseStoragePath,
                'metadata' => [
                    'firebaseStorageDownloadTokens' => $downloadToken
                ]
            ]);

            // Get file metadata
            $objectInfo = $object->info();
            $token = $objectInfo['metadata']['firebaseStorageDownloadTokens'] ?? $downloadToken;

            // Extract the Firebase Storage Download URL
            $url = "https://firebasestorage.googleapis.com/v0/b/" . $this->firebaseStorage->name() . "/o/" . urlencode($firebaseStoragePath) . "?alt=media&token=" . $token;

            return ['mediaLink' => $url]; // ✅ Correct Firebase Storage URL
        } catch (\Exception $e) {
            log_message('error', 'Failed to upload file to Firebase Storage: ' . $e->getMessage());
            return false;
        }
    }

    public function update_files_and_folder_in_firebase_storage($oldFolderPath, $newFolderPath, $files, $changeSchoolName = false, $updateFiles = false)
    {
        try {
            $updatedFiles = [];

            // Change the folder name if required
            if ($changeSchoolName) {
                $objects = $this->firebaseStorage->objects(['prefix' => $oldFolderPath]);

                foreach ($objects as $object) {
                    $oldObjectName = $object->name();
                    $newObjectName = str_replace($oldFolderPath, $newFolderPath, $oldObjectName);

                    // Copy the object to the new location
                    $object->copy($this->firebaseStorage, ['name' => $newObjectName]);
                    log_message('debug', "Copied object from $oldObjectName to $newObjectName");

                    // Delete the old object
                    $object->delete();
                    log_message('debug', "Deleted old object at $oldObjectName");
                }
            }

            // Update files if required
            if ($updateFiles) {
                foreach ($files as $type => $file) {
                    try {
                        if (isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                            $newFilePath = $file['tmp_name'];
                            $fileName = $type . '.jpg'; // Maintain .jpg extension
                            $newFirebasePath = ($changeSchoolName ? $newFolderPath : $oldFolderPath) . $type . '/' . $fileName;

                            // *Reuse handleFileUpload() for updating*
                            $url = $this->handleFileUpload($file, $newFolderPath, $type, $fileName, true, true);

                            log_message('debug', "Uploaded new file to path: $newFirebasePath with URL: $url");
                            $updatedFiles[$type] = $url;
                        } else {
                            log_message('debug', "No file uploaded for type: $type");
                        }
                    } catch (\Exception $e) {
                        log_message('error', "Failed to upload file for type $type: " . $e->getMessage());
                    }
                }
            }

            return $updatedFiles;
        } catch (\Exception $e) {
            log_message('error', 'Failed to update files and folder in Firebase Storage: ' . $e->getMessage());
            return false;
        }
    }

    public function get_file_url($firebaseStoragePath)
    {
        try {
            $object = $this->firebaseStorage->object($firebaseStoragePath);
            if (!$object->exists()) {
                return ''; // Return empty if the object does not exist
            }

            // Fetch object metadata
            $objectInfo = $object->info();

            // Retrieve the existing token, or generate a new one if missing
            $token = $objectInfo['metadata']['firebaseStorageDownloadTokens'] ?? bin2hex(random_bytes(16));

            // Construct the public URL with the token
            $bucketName = $this->firebaseStorage->name();
            $encodedPath = urlencode($firebaseStoragePath);
            $url = "https://firebasestorage.googleapis.com/v0/b/$bucketName/o/$encodedPath?alt=media&token=$token";

            return $url;
        } catch (\Exception $e) {
            log_message('error', 'Failed to get file URL from Firebase Storage: ' . $e->getMessage());
            return '';
        }
    }


    public function delete_file_from_firebase($firebaseStoragePath)
    {
        try {
            $object = $this->firebaseStorage->object($firebaseStoragePath);
            if ($object->exists()) {
                $object->delete();
                log_message('debug', "Deleted file at $firebaseStoragePath");
                return true;
            }
            return false;
        } catch (\Exception $e) {
            log_message('error', 'Failed to delete file from Firebase Storage: ' . $e->getMessage());
            return false;
        }
    }


    // public function validate_user($schoolId, $adminId, $password)
    // {
    //     $this->db->where('school_id', $schoolId);
    //     $this->db->where('admin_id', $adminId);
    //     $this->db->where('password', md5($password)); // Ensure password is hashed
    //     $query = $this->db->get('users');

    //     return $query->row_array();
    // }
}
