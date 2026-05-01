<div class="content-wrapper">
    <div class="page_container">
        <div class="box">
            <h3>Students (<?php echo sizeof($students) ?>)
                <a href="javascript:;" class="btn btn-success pull-right mr-2" style="margin-right:2%;"
                    data-toggle="modal" data-target="#myModal">Add New Student</a>
                <a href="javascript:;" class="btn btn-primary pull-right mr-2" data-toggle="modal"
                    data-target="#importModal" style="margin-right:2%;">
                    <i class="fa fa-upload" aria-hidden="true"></i> Import
                </a>
            </h3>
            <div style="padding-top:20px; padding-left: 10px; padding-right: 20px;">
                <div class="table-responsive">
                    <table class="table table-striped table-bordered example" style="width:100%">
                        <thead>
                            <tr>
                                <th>SNo.</th>
                                <th>User Id</th>
                                <th>Name</th>
                                <th>Father Name</th>
                                <th>Mother Name</th>
                                <th>Email</th>
                                <th>DOB</th>
                                <th>Phone Number</th>
                                <th>Gender</th>
                                <th>School Name</th>
                                <th>Class</th>
                                <th>Section</th>
                                <th>Address</th>
                                <th>Password</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>

                            <?php
                            $i = 1;
                            foreach ($students as $student) {
                            ?>
                            <tr>
                                <td><?php echo $i;  ?></td>
                                <td><?php echo $student['User Id']; ?></td>
                                <td><?php echo $student['Name']; ?></td>
                                <td><?php echo $student['Father Name']; ?></td>
                                <td><?php echo $student['Mother Name']; ?></td>
                                <td><?php echo $student['Email']; ?></td>
                                <td><?php echo $student['DOB']; ?></td>
                                <td><?php echo $student['Phone Number']; ?></td>
                                <td><?php echo $student['Gender']; ?></td>
                                <td><?php echo $student['School Name']; ?></td>
                                <td><?php echo $student['Class']; ?></td>
                                <td><?php echo $student['Section']; ?></td>
                                <td><?php echo $student['Address']; ?></td>
                                <td><span class="text-muted">********</span></td>
                                <td>
                                    <a href="<?php echo base_url() . 'index.php/student/delete_student/' . $student['User Id'] ?>"
                                        class="btn btn-danger"><i class="fa fa-trash-o" aria-hidden="true"></i></a>
                                    <a href="<?php echo base_url() . 'index.php/student/edit_student/' . $student['User Id'] ?>"
                                        class="btn btn-primary"><i class="fa fa-pencil" aria-hidden="true"></i></a>
                                </td>
                            </tr>
                            <?php
                                $i++;
                            }
                            ?>
                        </tbody>
                        <!-- <tfoot>
                            <tr>
                                <th>SNo.</th>
                                <th>User Id</th>
                                <th>Name</th>
                                <th>Father Name</th>
                                <th>Mother Name</th>
                                <th>Email</th>
                                <th>DOB</th>
                                <th>Phone Number</th>
                                <th>Gender</th>
                                <th>School Name</th>
                                <th>Class</th>
                                <th>Section</th>
                                <th>Address</th>
                                <th>Password</th>
                                <th>Action</th>
                            </tr>
                        </tfoot> -->
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div id="myModal" class="modal fade" role="dialog">
    <div class="modal-dialog">

        <!-- Modal content-->
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title text-center"><b>Add New Student</b></h4>
            </div>
            <div class="modal-body">
                <form action="<?php echo base_url() . 'index.php/student/student_registration' ?>"
                    id="add_student_form">
                    <div class="row">
                        <div class="form-group col-sm-6">
                            <label for="user_id">User Id</label>
                            <input type="text" name="User Id" required="required" value="<?php echo $newStudentId ?>"
                                id="user_id" class="form-control" placeholder="Enter User Id" readonly>
                        </div>
                        <div class="form-group col-sm-6">
                            <label for="school_name">School Name</label>
                            <input type="text" name="School Name" value="<?php echo $school_name  ?>"
                                required="required" id="school_name" class="form-control" readonly>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="sname">Enter Student Name</label>
                        <input type="text" name="Name" required="required" id="sname" class="form-control"
                            placeholder="Enter Student Name">
                    </div>

                    <div class="row">
                        <div class="form-group col-sm-6">
                            <label for="fname">Enter Father Name</label>
                            <input type="text" name="Father Name" required="required" id="fname" class="form-control"
                                placeholder="Enter Father Name">
                        </div>
                        <div class="form-group col-sm-6">
                            <label for="mname">Enter Mother Name</label>
                            <input type="text" name="Mother Name" required="required" id="mname" class="form-control"
                                placeholder="Enter Mother Name">
                        </div>
                    </div>


                    <div class="row">
                        <div class="form-group col-sm-6">
                            <label for="email_user">Enter Email</label>
                            <input type="email" name="Email" required="required" id="email_user" class="form-control"
                                placeholder="Enter Email">
                        </div>
                        <div class="form-group col-sm-6">
                            <label for="dob">Enter Student DOB </label>
                            <input type="date" name="DOB" required="required" id="dob" class="form-control">
                        </div>
                    </div>

                    <div class="row">
                        <div class="form-group col-sm-6">
                            <label for="phone">Enter Phone Number</label>
                            <div class="row">
                                <div class="form-group col-md-3">
                                    <input type="text" class="form-control" value="+91" readonly>
                                </div>
                                <div class="form-group col-xs-9">
                                    <input type="text" name="Phone Number" required="required" id="phone"
                                        class="form-control">
                                    <small id="phone-error" style="color: red; display: none;">Invalid phone
                                        number.</small>
                                </div>
                            </div>
                        </div>
                    </div>


                    <div class="form-group col-sm-6">
                        <label for="gender">Select Gender</label>
                        <select name="Gender" id="gender" class="form-control" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
            </div>

            <div class="row">
                <div class="col-sm-6">
                    <label for="class">Select Class</label>
                    <select name="Class" required="required" id="class" class="form-control">
                        <option value="">Select Class</option>
                        <?php foreach ($classNames as $className) : ?>
                        <option value="<?php echo htmlspecialchars($className); ?>">
                            Class <?php echo htmlspecialchars($className); ?>
                            <!-- Added 'Class ' prefix -->
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label for="section">Select Section</label>
                    <select name="Section" required="required" id="section" class="form-control" disabled>
                        <option value="">Select Section</option>
                    </select>
                </div>
            </div>



            <div class="form-group">
                <label for="address">Enter Address</label>
                <input type="text" name="Address" required="required" id="address" class="form-control">
            </div>

            <!-- <div class="form-group">
                        <label for="password">Enter Password</label>
                        <input type="text" name="Password" required="required" id="password" class="form-control">
                    </div> -->

            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-lg">Add Student</button>
            </div>
            </form>
        </div>
    </div>
</div>
</div>



<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel"><b>Import Students</b></h5>
            </div>
            <div class="modal-body">
                <form action="<?php echo base_url() . 'index.php/student/import_students' ?>" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>" 
           value="<?= $this->security->get_csrf_hash() ?>">
                    <div class="form-group">
                        <label for="excelFile">Upload Excel File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="excelFile" name="excelFile" accept=".xls,.xlsx" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>
            </div>
        </div>
    </div>
</div>


<script>
document.getElementById('class').addEventListener('change', function() {
    var classSelect = this;
    var selectedClass = classSelect.value;
    var sectionSelect = document.getElementById('section');

    // Clear existing options
    sectionSelect.innerHTML = '<option value="">Select Section</option>';

    // Enable section dropdown
    sectionSelect.disabled = false;

    // Populate section options based on selected class
    var sections = <?php echo json_encode($classSections); ?>;
    if (sections[selectedClass]) {
        var classSections = sections[selectedClass];
        for (var section in classSections) {
            if (classSections.hasOwnProperty(section)) {
                var option = document.createElement('option');
                option.value = section;
                option.text = section;
                sectionSelect.appendChild(option);
            }
        }
    } else {
        // If no sections found for selected class, disable section dropdown
        sectionSelect.disabled = true;
    }
});


// Function to handle input in the phone number field
document.getElementById('phone').addEventListener('input', function(event) {
    var phoneInput = event.target;
    phoneInput.value = phoneInput.value.replace(/\D/g, ''); // Allow only numbers

    // Check if the current input is valid
    var phoneNumber = phoneInput.value;
    var phoneError = document.getElementById('phone-error');

    if (phoneNumber.length === 0) {
        phoneError.style.display = 'none'; // Hide error if input is empty
    } else if (phoneNumber.length < 10) {
        phoneError.innerText = 'Phone number should be 10 digits long.';
        phoneError.style.display = 'inline'; // Show error if less than 10 digits
    } else if (!/^6789/.test(phoneNumber)) {
        phoneError.innerText = 'Invalid Phone number.';
        phoneError.style.display = 'inline'; // Show error if not starting with6, 7, 8, or 9
    } else {
        phoneError.style.display = 'none'; // Hide error if valid
    }
});

// Function to handle blur event in the phone number field
document.getElementById('phone').addEventListener('blur', function(event) {
    var phoneInput = event.target;
    var phoneNumber = phoneInput.value;
    var phoneError = document.getElementById('phone-error');

    // Check final validity on blur
    if (phoneNumber.length < 10 || !/^6789/.test(phoneNumber)) {
        phoneError.style.display = 'inline'; // Show error on blur if not valid
    } else {
        phoneError.style.display = 'none'; // Hide error if valid
    }
});
</script>
<!-- <style>
    .offline {
    color: red;
    font-weight: bold;
    font-size: x-large;
}

</style> -->