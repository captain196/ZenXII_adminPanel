<div class="content-wrapper">
    <div class="page_container">
        <div class="box">
            <h1 class="text-center">Edit School</h1>
            <div style="padding-top:20px; padding-left: 10px; padding-right: 20px;">
                <div class="row">
                    <div class="col-sm-1"></div>
                    <div class="col-sm-10">
                        <form action="<?php echo base_url() . 'index.php/schools/edit_school/'.$schooll['School Id'] ?>" id="edit_school"
                            method="post" enctype="multipart/form-data">
                            <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>" 
           value="<?= $this->security->get_csrf_hash() ?>">
                            <div class="form-group input-group mb-3">
                                <label>Enter School Id</label>
                                <input type="text" name="School Id" required="required" id="school_id"
                                    class="form-control" placeholder="Enter School Id" aria-label="Sizing example input"
                                    aria-describedby="inputGroup-sizing-default" value="<?php echo $schooll['School Id']; ?>" readonly>
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>Enter School Name</label>
                                <input type="text" name="School Name" required="required" id="school_name"
                                    class="form-control" placeholder="Enter School Name"
                                    aria-label="Sizing example input" aria-describedby="inputGroup-sizing-default" value="<?php echo $schooll['School Name']; ?>">
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>Update School Logo</label>
                                <input type="file" name="school_logos" id="school_logos"
                                    class="form-control" aria-label="Upload School Logo"
                                    aria-describedby="inputGroup-sizing-default">
                                <?php if (!empty($school_logo_url)) { ?>
                                    <img src="<?php echo $school_logo_url; ?>" class="circular-image">
                                <?php } ?>
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>Update Holiday Calendar</label>
                                <input type="file" name="holidays" id="holidays"
                                    class="form-control" aria-label="Upload Holiday Calendar"
                                    aria-describedby="inputGroup-sizing-default">
                                <?php if (!empty($holidays_url)) { ?>
                                    <img src="<?php echo $holidays_url; ?>" class="circular-image">
                                <?php } ?>
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>Update Academic Calendar</label>
                                <input type="file" name="academic" id="academic"
                                    class="form-control" aria-label="Upload Academic Calendar"
                                    aria-describedby="inputGroup-sizing-default">
                                <?php if (!empty($academic_url)) { ?>
                                    <img src="<?php echo $academic_url; ?>" class="circular-image">
                                <?php } ?>
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>Affiliated To</label>
                                <input type="text" name="Affiliated To" required="required" id="affiliated_to"
                                    class="form-control" placeholder="Affiliated To"
                                    value="<?php echo isset($schooll['Affiliated To']) ? $schooll['Affiliated To'] : ''; ?>">
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>Affiliation Number</label>
                                <input type="text" name="Affiliation Number" required="required" id="affiliation_number"
                                    class="form-control" placeholder="Affiliation Number"
                                    value="<?php echo isset($schooll['Affiliation Number']) ? $schooll['Affiliation Number'] : ''; ?>">
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>School Address</label>
                                <input type="text" name="Address" required="required" id="school_address"
                                    class="form-control" placeholder="School Address"
                                    value="<?php echo isset($schooll['Address']) ? $schooll['Address'] : ''; ?>">
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>Phone Number</label>
                                <input type="text" name="Phone Number" required="required" id="phone_number"
                                    class="form-control" placeholder="Phone Number"
                                    value="<?php echo isset($schooll['Phone Number']) ? $schooll['Phone Number'] : ''; ?>">
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>Mobile Number</label>
                                <input type="text" name="Mobile Number" required="required" id="mobile_number"
                                    class="form-control" placeholder="Mobile Number"
                                    value="<?php echo isset($schooll['Mobile Number']) ? $schooll['Mobile Number'] : ''; ?>">
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>Email</label>
                                <input type="email" name="Email" required="required" id="email"
                                    class="form-control" placeholder="Email"
                                    value="<?php echo isset($schooll['Email']) ? $schooll['Email'] : ''; ?>">
                            </div>

                            <div class="form-group input-group mb-3">
                                <label>Website</label>
                                <input type="text" name="Website" required="required" id="website"
                                    class="form-control" placeholder="Website"
                                    value="<?php echo isset($schooll['Website']) ? $schooll['Website'] : ''; ?>">
                            </div>

                            <div class="form-group text-center col-sm-1">
                                <button type="submit" class="btn btn-primary">Update</button>
                            </div>
                            <div class="form-group text-center col-sm-3">
                                <button type="button" class="btn btn-danger" onclick="goBack()">Cancel</button>
                            </div>
                        </form>

                    </div>
                    <div class="col-sm-1"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.circular-image {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
}
</style>

<script>
function goBack() {
    window.history.back();
}
</script>
