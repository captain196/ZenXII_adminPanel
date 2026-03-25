<div class="content-wrapper">
    <div class="page_container">
        <div class="box">
            <h3>Students (<?php echo sizeof($student_offline) ?>)
                <a href="javascript:;" class="btn btn-success pull-right mr-2" style="margin-right:2%;"
                    data-toggle="modal" data-target="#myModal">Add New Student</a>
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
                                <th>Email Id</th>
                                <th>DOB</th>
                                <th>Phone Number</th>
                                <th>Gender</th>
                                <th>School Name</th>
                                <th>Class Name</th>
                                <th>Section</th>
                                <th>Address</th>
                                <th>Encrypted Password</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>

                            <?php
                                    $i = 1;
                                    foreach ($student_offline as $student) {
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
                        <tfoot>
                            <tr>
                                <th>SNo.</th>
                                <th>User Id</th>
                                <th>Name</th>
                                <th>Father Name</th>
                                <th>Mother Name</th>
                                <th>Email Id</th>
                                <th>DOB</th>
                                <th>Phone Number</th>
                                <th>Gender</th>
                                <th>School Name</th>
                                <th>Class Name</th>
                                <th>Section</th>
                                <th>Address</th>
                                <th>Encrypted Password</th>
                                <th>Action</th>
                            </tr>
                        </tfoot>
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
                    id="add_student_form_offline">
                    <div class="row">
                        <div class="form-group col-sm-6">
                            <label for="user_id">Student Id</label>
                            <input type="text" name="User Id" required="required" id="user_id" class="form-control"
                                placeholder="Enter User Id">
                        </div>
                        <div class="form-group col-sm-6">
                            <label for="school_name">School Name</label>
                            <input type="text" name="School Name" required="required" id="school_name"
                                class="form-control">
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
                    <input name="Class" required="required" id="class" class="form-control">
                    <!-- <option value="">Select Class</option>

                        <option value="8th">8th</option> -->

                    <!-- <?php foreach ($classNames as $className) : ?>
                        <option value="<?php echo htmlspecialchars($className); ?>">
                            Class <?php echo htmlspecialchars($className); ?>
                            
                        </option>
                        <?php endforeach; ?> -->

                </div>
                <div class="col-sm-6">
                    <label for="section">Section:</label>
                    <input type="text" class="form-control" id="section" name="Section">
                </div>
            </div>



            <div class="form-group">
                <label for="address">Enter Address</label>
                <input type="text" name="Address" required="required" id="address" class="form-control">
            </div>

            <div class="form-group">
                <label for="password">Enter Password</label>
                <input type="text" name="Password" required="required" id="password" class="form-control">
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-lg">Add Student</button>
            </div>
            </form>
        </div>
    </div>
</div>
</div>