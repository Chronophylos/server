<?php namespace App\Models;

use CodeIgniter\Model;

class NotepadModel extends Model
{
    protected $table      = "notepads";
    protected $primaryKey = "document";
    protected $returnType = "object";

    protected $useSoftDeletes = true;

    protected $allowedFields = ["document", "content", "created", "updated", "deleted"];

    protected $useTimestamps = true;
    protected $createdField  = "created";
    protected $updatedField  = "updated";
    protected $deletedField  = "deleted";

    protected $validationRules = [
        "document" => "required|min_length[35]",
    ];
}