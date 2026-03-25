<div class="content-wrapper">
    <div class="page_container">
        <div class="box">
            <h3>Staff (<?php echo sizeof($staff)?>)
                <a href="javascript:;" class="btn btn-success pull-right" data-toggle="modal" data-target="#myModal">Add
                    New Staff</a>
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
                                <th>SNO.</th>
                                <th>User Id</th>
                                <th>Name</th>
                                <th>School Name</th>
                                <th>Gender</th>
                                <th>Email</th>
                                <th>Phone Number</th>
                                <th>Password</th>
                                <th>Address</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                           $i=1;
                           foreach ($staff as $s1) 
                           {
                            // if (!is_array($s1)) continue;
                            ?>
                            <tr>
                                <td><?php echo $i; ?></td>
                                <td><?php echo $s1['User Id']; ?></td>
                                <td><?php echo $s1['Name']; ?></td>
                                <td><?php echo $s1['School Name']; ?></td>
                                <td><?php echo $s1['Gender']; ?></td>
                                <td><?php echo $s1['Email']; ?></td>
                                <td><?php echo $s1['Phone Number']; ?></td>
                                <td><span class="text-muted">********</span></td>
                                <td><?php echo $s1['Address']; ?></td>
                                <td>
                                    <a href="<?php echo base_url().'index.php/staff/delete_staff/'. $s1['User Id'] ?>"
                                        class="btn btn-danger"><i class="fa fa-trash-o" aria-hidden="true"></i></a>
                                    <a href="<?php echo base_url().'index.php/staff/edit_staff/'. $s1['User Id'] ?>"
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
                                <th>SNO</th>
                                <th>User Id</th>
                                <th>Name</th>
                                <th>School Name</th>
                                <th>Gender</th>
                                <th>Email</th>
                                <th>Phone Number</th>
                                <th>Password</th>
                                <th>Address</th>
                                <th>Action</th>
                            </tr>
                        </tfoot> -->
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel"><b>Import Staff</b></h5>
            </div>
            <div class="modal-body">
                <form action="<?php echo base_url() . 'index.php/staff/import_staff' ?>" method="post" enctype="multipart/form-data">
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


<!-- Modal -->
<div id="myModal" class="modal fade" role="dialog">
    <div class="modal-dialog">

        <!-- Modal content-->
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title text-center"><b>Add New Staff</b></h4>
            </div>
            <div class="modal-body">

                <form action="<?php echo base_url() .'index.php/staff/manage_staff' ?>" id="add_staff_form">

                    <div class="row">
                        <div class="form-group col-sm-6">
                            <label for="user_id">Enter Staff ID</label>
                            <input type="text" name="User Id" required="required" id="user_id"
                                value="<?php echo $newStaffId  ?>" class="form-control" placeholder="Enter Staff Id"
                                readonly>
                        </div>
                        <div class="form-group col-sm-6">
                            <label for="school_name">Enter School Name</label>
                            <input type="text" name="School Name" required="required" id="school_name"
                                class="form-control" placeholder="Enter School Name" value="<?php echo $school_name ?>"
                                readonly>
                        </div>

                    </div>
                    <div class="row">
                        <div class="form-group col-sm-6">
                            <label for="name">Enter Staff Name</label>
                            <input type="text" name="Name" required="required" id="name" class="form-control"
                                placeholder="Enter Staff Name">
                        </div>
                        <div class="form-group col-sm-6">
                            <label for="phone number">Enter Phone Number </label>
                            <input type="number" name="Phone Number" required="required" id="phone_number"
                                class="form-control" placeholder="Enter Mobile">
                        </div>
                    </div>

                    <div class="row">
                        <div class="form-group col-sm-5">
                            <label for="gender">Select Gender</label>
                            <select name="Gender" id="gender" class="form-control" required>
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>

                        <div class="form-group col-sm-7">
                            <label for="email">Enter Email </label>
                            <input type="email" name="Email" required="required" id="email" class="form-control"
                                placeholder="Enter Email">
                        </div>

                    </div>

                    <!-- 
                    <div class="form-group">
                        <label for="password">Enter Password</label>
                        <input type="text" name="Password"  required="required" id="password" class="form-control" value="<?php echo isset($_POST['Name']) ? substr($_POST['Name'], 0, 3) . '123@' : ''; ?>"
                        >
                    </div> -->

                    <div class="form-group">
                        <label for="fname">Enter Address</label>
                        <input type="text" name="Address" required="required" id="address" class="form-control"
                            placeholder="Enter Address">
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary btn-lg">Add Staff</button>
                    </div>

                </form>

            </div>

        </div>

    </div>

</div>