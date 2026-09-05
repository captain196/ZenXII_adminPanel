<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * GENERATED FILE — DO NOT EDIT BY HAND.
 *
 *   node tools/gen_doc_starters.js
 *
 * The starter templates are AUTHORED in assets/js/doctemplates/designer.js. This is a
 * generated server-side copy so a school can be provisioned with the standard documents
 * without a human opening the designer. `DocStarterParityTest` regenerates and diffs,
 * so editing a starter and forgetting to regenerate fails the suite rather than shipping
 * two different standard certificates.
 *
 * Each row: id, docType, name, meta, boards (null = any), states (null = any), template.
 * The board/state gates are the SAME ones the designer applies in startersFor() — a
 * Kerala-only form must not be seeded into a school in another state.
 *
 * Starters: 7
 */
$config['doc_starters'] = json_decode(<<<'JSON'
[
  {
    "id": "lc_5a",
    "docType": "leaving_certificate_5a",
    "name": "Leaving Certificate — Form 5A",
    "meta": "Kerala · r.17(3) · field list not retrieved",
    "boards": null,
    "states": [
      "Kerala"
    ],
    "template": {
      "templateId": "TPL0061",
      "schoolId": "SCH_D94FE8F7AD",
      "docType": "leaving_certificate_5a",
      "name": "Leaving Certificate — Form 5A",
      "status": "draft",
      "version": 1,
      "publishedVersion": null,
      "activeVersion": null,
      "lockVersion": 17,
      "complianceProfileId": "cbse",
      "complianceProfileVersion": 4,
      "contractRef": "transfer_certificate_v3",
      "languages": [
        "en"
      ],
      "defaultLanguage": "en",
      "languageFallback": "block",
      "page": {
        "size": "A4",
        "orientation": "portrait",
        "marginsMm": {
          "t": 42,
          "r": 15,
          "b": 16,
          "l": 15
        },
        "pageMode": "single"
      },
      "objects": [
        {
          "id": "h_logo",
          "name": "School crest",
          "region": "header",
          "type": "image",
          "xMm": 15,
          "yMm": 8,
          "wMm": 20,
          "hMm": 20,
          "z": 1,
          "height": "fixed",
          "content": {
            "label": "School crest"
          },
          "style": {}
        },
        {
          "id": "h_name",
          "name": "School name",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 9,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "requiredKey": "school.name",
          "style": {
            "sizePt": 16,
            "lineHeight": 1.2,
            "weight": 700,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.name"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_addr",
          "name": "Address line",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 20,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.35,
            "weight": 400,
            "align": "left",
            "colour": "#4A3C33"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.address"
                  },
                  {
                    "t": "  ·  Affiliation No. "
                  },
                  {
                    "f": "school.affiliationNo"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_rule",
          "name": "Letterhead rule",
          "region": "header",
          "type": "shape",
          "xMm": 15,
          "yMm": 33,
          "wMm": 180,
          "hMm": 0.6,
          "z": 1,
          "height": "fixed",
          "content": {
            "shape": "line"
          },
          "style": {
            "colour": "#14100D"
          }
        },
        {
          "id": "t_title",
          "name": "Title",
          "type": "text",
          "xMm": 15,
          "yMm": 46,
          "wMm": 180,
          "hMm": 8,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 14,
            "lineHeight": 1.25,
            "weight": 700,
            "align": "center",
            "colour": "#14100D",
            "track": ".14em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "LEAVING CERTIFICATE"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_table",
          "name": "Particulars table",
          "type": "table",
          "xMm": 15,
          "yMm": 66,
          "wMm": 180,
          "hMm": 120,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.55,
            "colour": "#14100D"
          },
          "content": {
            "rows": [
              {
                "key": "student.admissionNumber"
              },
              {
                "key": "student.fullName"
              },
              {
                "key": "student.fatherName"
              },
              {
                "key": "student.dob"
              },
              {
                "key": "student.dobWords"
              },
              {
                "key": "tc.dateOfFirstAdmission"
              },
              {
                "key": "tc.lastClassStudied"
              },
              {
                "key": "student.ageAtLeaving"
              },
              {
                "key": "tc.dateOfLeaving"
              }
            ]
          }
        },
        {
          "id": "t_reason",
          "name": "Reason for leaving",
          "type": "text",
          "xMm": 15,
          "yMm": 190,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_table",
          "anchorGapMm": 5,
          "requiredKey": null,
          "maxHMm": 12,
          "style": {
            "sizePt": 9,
            "lineHeight": 1.55,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Reason for removal from the rolls: "
                  },
                  {
                    "f": "sec.outcome"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_issue",
          "name": "Date of issue",
          "type": "text",
          "xMm": 15,
          "yMm": 204,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_reason",
          "anchorGapMm": 4,
          "requiredKey": "doc.issueDate",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.55,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "16.  Date of issue of this certificate: "
                  },
                  {
                    "f": "doc.issueDate"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_declare",
          "name": "Declaration",
          "type": "text",
          "xMm": 15,
          "yMm": 214,
          "wMm": 180,
          "hMm": 10,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_issue",
          "anchorGapMm": 6,
          "style": {
            "sizePt": 8,
            "lineHeight": 1.5,
            "weight": 400,
            "align": "left",
            "colour": "#4A3C33"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Issued under Rule 17(3) of the Kerala Education Rules 1959 in place of a transfer certificate, the pupil having been removed from the rolls while over twenty years of age."
                  }
                ]
              }
            }
          }
        },
        {
          "id": "sig_ct",
          "sigRole": "class_teacher",
          "name": "Sig · Class Teacher",
          "type": "text",
          "xMm": 15,
          "yMm": 252,
          "wMm": 52,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Class Teacher"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "sig_ck",
          "sigRole": "checked_by",
          "name": "Sig · Checked by",
          "type": "text",
          "xMm": 79,
          "yMm": 252,
          "wMm": 52,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "showWhen": "tc.duesPaidUpto",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Checked by"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "sig_pr",
          "sigRole": "principal",
          "name": "Sig · Principal",
          "type": "text",
          "xMm": 143,
          "yMm": 252,
          "wMm": 52,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Principal"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "o_seal",
          "name": "School seal",
          "type": "shape",
          "xMm": 88,
          "yMm": 228,
          "wMm": 34,
          "hMm": 34,
          "z": 2,
          "height": "fixed",
          "content": {
            "shape": "seal"
          },
          "style": {}
        },
        {
          "id": "t_dup",
          "name": "Duplicate mark",
          "type": "text",
          "xMm": 130,
          "yMm": 38,
          "wMm": 65,
          "hMm": 8,
          "z": 8,
          "height": "auto",
          "showWhen": "doc.isDuplicate",
          "isDuplicateMark": true,
          "style": {
            "sizePt": 15,
            "lineHeight": 1.2,
            "weight": 700,
            "align": "right",
            "colour": "#C0392B",
            "track": ".22em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "DUPLICATE"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "f_no",
          "name": "Page number",
          "region": "footer",
          "type": "pageNumber",
          "xMm": 15,
          "yMm": 4,
          "wMm": 180,
          "hMm": 5,
          "z": 1,
          "height": "fixed",
          "style": {
            "sizePt": 7.5,
            "lineHeight": 1.3,
            "align": "center",
            "colour": "#7A6A60"
          },
          "content": {
            "format": "Page {n} of {t}"
          }
        }
      ]
    }
  },
  {
    "id": "tc_cbse",
    "docType": "transfer_certificate",
    "name": "Annexure-I form",
    "meta": "CBSE · 22 fields · Level A",
    "boards": [
      "CBSE"
    ],
    "states": null,
    "template": {
      "templateId": "TPL0007",
      "schoolId": "SCH_D94FE8F7AD",
      "docType": "transfer_certificate",
      "name": "TC — main letterhead",
      "status": "draft",
      "version": 3,
      "publishedVersion": 2,
      "activeVersion": 2,
      "lockVersion": 17,
      "complianceProfileId": "cbse",
      "complianceProfileVersion": 4,
      "contractRef": "transfer_certificate_v3",
      "languages": [
        "en",
        "hi"
      ],
      "defaultLanguage": "en",
      "languageFallback": "block",
      "page": {
        "size": "A4",
        "orientation": "portrait",
        "marginsMm": {
          "t": 42,
          "r": 15,
          "b": 16,
          "l": 15
        },
        "pageMode": "single"
      },
      "objects": [
        {
          "id": "h_logo",
          "name": "School crest",
          "region": "header",
          "type": "image",
          "xMm": 15,
          "yMm": 8,
          "wMm": 20,
          "hMm": 20,
          "z": 1,
          "height": "fixed",
          "content": {
            "label": "School crest"
          },
          "style": {}
        },
        {
          "id": "h_name",
          "name": "School name",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 9,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "requiredKey": "school.name",
          "style": {
            "sizePt": 16,
            "lineHeight": 1.2,
            "weight": 700,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.name"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "f": "school.name"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_addr",
          "name": "Address line",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 20,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.35,
            "weight": 400,
            "align": "left",
            "colour": "#4A3C33"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.address"
                  },
                  {
                    "t": "  ·  Affiliation No. "
                  },
                  {
                    "f": "school.affiliationNo"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "f": "school.address"
                  },
                  {
                    "t": "  ·  संबद्धता क्रमांक "
                  },
                  {
                    "f": "school.affiliationNo"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_rule",
          "name": "Letterhead rule",
          "region": "header",
          "type": "shape",
          "xMm": 15,
          "yMm": 33,
          "wMm": 180,
          "hMm": 0.6,
          "z": 1,
          "height": "fixed",
          "content": {
            "shape": "line"
          },
          "style": {
            "colour": "#14100D"
          }
        },
        {
          "id": "t_title",
          "name": "Title",
          "type": "text",
          "xMm": 15,
          "yMm": 46,
          "wMm": 180,
          "hMm": 8,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 14,
            "lineHeight": 1.25,
            "weight": 700,
            "align": "center",
            "colour": "#14100D",
            "track": ".14em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "TRANSFER CERTIFICATE"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "स्थानांतरण प्रमाणपत्र"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_book",
          "name": "Book / Sl. No.",
          "type": "text",
          "xMm": 15,
          "yMm": 57,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "requiredKey": "doc.bookNo",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.35,
            "weight": 600,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Book No. "
                  },
                  {
                    "f": "doc.bookNo"
                  },
                  {
                    "t": "          Sl. No. "
                  },
                  {
                    "f": "doc.slNo"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "पुस्तक सं. "
                  },
                  {
                    "f": "doc.bookNo"
                  },
                  {
                    "t": "          क्रम सं. "
                  },
                  {
                    "f": "doc.slNo"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_table",
          "name": "Particulars table",
          "type": "table",
          "xMm": 15,
          "yMm": 66,
          "wMm": 180,
          "hMm": 120,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.55,
            "colour": "#14100D"
          },
          "content": {
            "rows": [
              {
                "key": "student.admissionNumber"
              },
              {
                "key": "student.fullName"
              },
              {
                "key": "student.fatherName"
              },
              {
                "key": "student.motherName"
              },
              {
                "key": "student.dob"
              },
              {
                "key": "student.dobWords"
              },
              {
                "key": "tc.dateOfFirstAdmission"
              },
              {
                "key": "tc.lastClassStudied"
              },
              {
                "key": "attendance.workingDays"
              },
              {
                "key": "attendance.daysPresent"
              },
              {
                "key": "result.promotionEligible"
              },
              {
                "key": "tc.conductRemark"
              },
              {
                "key": "tc.duesPaidUpto"
              },
              {
                "key": "tc.dateOfLeaving"
              }
            ]
          }
        },
        {
          "id": "t_reason",
          "name": "Reason for leaving",
          "type": "text",
          "xMm": 15,
          "yMm": 190,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_table",
          "anchorGapMm": 5,
          "requiredKey": "tc.reasonForLeaving",
          "maxHMm": 12,
          "style": {
            "sizePt": 9,
            "lineHeight": 1.55,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "15.  Reason for leaving the school: "
                  },
                  {
                    "f": "tc.reasonForLeaving"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "15.  विद्यालय छोड़ने का कारण: "
                  },
                  {
                    "f": "tc.reasonForLeaving"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_issue",
          "name": "Date of issue",
          "type": "text",
          "xMm": 15,
          "yMm": 204,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_reason",
          "anchorGapMm": 4,
          "requiredKey": "doc.issueDate",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.55,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "16.  Date of issue of this certificate: "
                  },
                  {
                    "f": "doc.issueDate"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "16.  प्रमाणपत्र जारी करने की तिथि: "
                  },
                  {
                    "f": "doc.issueDate"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_declare",
          "name": "Declaration",
          "type": "text",
          "xMm": 15,
          "yMm": 214,
          "wMm": 180,
          "hMm": 10,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_issue",
          "anchorGapMm": 6,
          "style": {
            "sizePt": 8,
            "lineHeight": 1.5,
            "weight": 400,
            "align": "left",
            "colour": "#4A3C33"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Certified that the above particulars are true to the best of my knowledge and are taken from the Admission and Withdrawal Register of this school."
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "प्रमाणित किया जाता है कि उपर्युक्त विवरण मेरी जानकारी के अनुसार सत्य हैं तथा विद्यालय के प्रवेश एवं निष्कासन पंजिका से लिए गए हैं।"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "sig_ct",
          "sigRole": "class_teacher",
          "name": "Sig · Class Teacher",
          "type": "text",
          "xMm": 15,
          "yMm": 252,
          "wMm": 52,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Class Teacher"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "कक्षा अध्यापक"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "sig_ck",
          "sigRole": "checked_by",
          "name": "Sig · Checked by",
          "type": "text",
          "xMm": 79,
          "yMm": 252,
          "wMm": 52,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "showWhen": "tc.duesPaidUpto",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Checked by"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "जाँचकर्ता"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "sig_pr",
          "sigRole": "principal",
          "name": "Sig · Principal",
          "type": "text",
          "xMm": 143,
          "yMm": 252,
          "wMm": 52,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Principal"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "प्रधानाचार्य"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "o_seal",
          "name": "School seal",
          "type": "shape",
          "xMm": 88,
          "yMm": 228,
          "wMm": 34,
          "hMm": 34,
          "z": 2,
          "height": "fixed",
          "content": {
            "shape": "seal"
          },
          "style": {}
        },
        {
          "id": "t_dup",
          "name": "Duplicate mark",
          "type": "text",
          "xMm": 130,
          "yMm": 38,
          "wMm": 65,
          "hMm": 8,
          "z": 8,
          "height": "auto",
          "showWhen": "doc.isDuplicate",
          "isDuplicateMark": true,
          "style": {
            "sizePt": 15,
            "lineHeight": 1.2,
            "weight": 700,
            "align": "right",
            "colour": "#C0392B",
            "track": ".22em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "DUPLICATE"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "द्वितीय प्रति"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "f_no",
          "name": "Page number",
          "region": "footer",
          "type": "pageNumber",
          "xMm": 15,
          "yMm": 4,
          "wMm": 180,
          "hMm": 5,
          "z": 1,
          "height": "fixed",
          "style": {
            "sizePt": 7.5,
            "lineHeight": 1.3,
            "align": "center",
            "colour": "#7A6A60"
          },
          "content": {
            "format": "Page {n} of {t}"
          }
        }
      ]
    }
  },
  {
    "id": "tc_plain",
    "docType": "transfer_certificate",
    "name": "Plain letterhead",
    "meta": "any board · prose form",
    "boards": null,
    "states": null,
    "template": {
      "templateId": "TPL0051",
      "schoolId": "SCH_D94FE8F7AD",
      "docType": "transfer_certificate",
      "name": "TC — plain letterhead",
      "status": "draft",
      "version": 1,
      "publishedVersion": null,
      "activeVersion": null,
      "lockVersion": 17,
      "complianceProfileId": "cbse",
      "complianceProfileVersion": 4,
      "contractRef": "transfer_certificate_v3",
      "languages": [
        "en",
        "hi"
      ],
      "defaultLanguage": "en",
      "languageFallback": "block",
      "page": {
        "size": "A4",
        "orientation": "portrait",
        "marginsMm": {
          "t": 42,
          "r": 15,
          "b": 16,
          "l": 15
        },
        "pageMode": "single"
      },
      "objects": [
        {
          "id": "h_logo",
          "name": "School crest",
          "region": "header",
          "type": "image",
          "xMm": 15,
          "yMm": 8,
          "wMm": 20,
          "hMm": 20,
          "z": 1,
          "height": "fixed",
          "content": {
            "label": "School crest"
          },
          "style": {}
        },
        {
          "id": "h_name",
          "name": "School name",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 9,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "requiredKey": "school.name",
          "style": {
            "sizePt": 16,
            "lineHeight": 1.2,
            "weight": 700,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.name"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "f": "school.name"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_addr",
          "name": "Address line",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 20,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.35,
            "weight": 400,
            "align": "left",
            "colour": "#4A3C33"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.address"
                  },
                  {
                    "t": "  ·  Affiliation No. "
                  },
                  {
                    "f": "school.affiliationNo"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "f": "school.address"
                  },
                  {
                    "t": "  ·  संबद्धता क्रमांक "
                  },
                  {
                    "f": "school.affiliationNo"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_rule",
          "name": "Letterhead rule",
          "region": "header",
          "type": "shape",
          "xMm": 15,
          "yMm": 33,
          "wMm": 180,
          "hMm": 0.6,
          "z": 1,
          "height": "fixed",
          "content": {
            "shape": "line"
          },
          "style": {
            "colour": "#14100D"
          }
        },
        {
          "id": "t_title",
          "name": "Title",
          "type": "text",
          "xMm": 15,
          "yMm": 46,
          "wMm": 180,
          "hMm": 8,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 14,
            "lineHeight": 1.25,
            "weight": 700,
            "align": "center",
            "colour": "#14100D",
            "track": ".14em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "TRANSFER / SCHOOL LEAVING CERTIFICATE"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "स्थानांतरण प्रमाणपत्र"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_table",
          "name": "Particulars table",
          "type": "table",
          "xMm": 15,
          "yMm": 66,
          "wMm": 180,
          "hMm": 120,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.55,
            "colour": "#14100D"
          },
          "content": {
            "rows": [
              {
                "key": "student.admissionNumber"
              },
              {
                "key": "student.fullName"
              },
              {
                "key": "student.fatherName"
              },
              {
                "key": "student.motherName"
              },
              {
                "key": "student.dob"
              },
              {
                "key": "student.dobWords"
              },
              {
                "key": "tc.dateOfFirstAdmission"
              },
              {
                "key": "tc.lastClassStudied"
              },
              {
                "key": "attendance.workingDays"
              },
              {
                "key": "attendance.daysPresent"
              },
              {
                "key": "result.promotionEligible"
              },
              {
                "key": "tc.conductRemark"
              },
              {
                "key": "tc.duesPaidUpto"
              },
              {
                "key": "tc.dateOfLeaving"
              }
            ]
          }
        },
        {
          "id": "t_reason",
          "name": "Reason for leaving",
          "type": "text",
          "xMm": 15,
          "yMm": 190,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_table",
          "anchorGapMm": 5,
          "requiredKey": "tc.reasonForLeaving",
          "maxHMm": 12,
          "style": {
            "sizePt": 9,
            "lineHeight": 1.55,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "15.  Reason for leaving the school: "
                  },
                  {
                    "f": "tc.reasonForLeaving"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "15.  विद्यालय छोड़ने का कारण: "
                  },
                  {
                    "f": "tc.reasonForLeaving"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_issue",
          "name": "Date of issue",
          "type": "text",
          "xMm": 15,
          "yMm": 204,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_reason",
          "anchorGapMm": 4,
          "requiredKey": "doc.issueDate",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.55,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "16.  Date of issue of this certificate: "
                  },
                  {
                    "f": "doc.issueDate"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "16.  प्रमाणपत्र जारी करने की तिथि: "
                  },
                  {
                    "f": "doc.issueDate"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_declare",
          "name": "Declaration",
          "type": "text",
          "xMm": 15,
          "yMm": 214,
          "wMm": 180,
          "hMm": 10,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_issue",
          "anchorGapMm": 6,
          "style": {
            "sizePt": 8,
            "lineHeight": 1.5,
            "weight": 400,
            "align": "left",
            "colour": "#4A3C33"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Certified that the above particulars are true to the best of my knowledge and are taken from the Admission and Withdrawal Register of this school."
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "प्रमाणित किया जाता है कि उपर्युक्त विवरण मेरी जानकारी के अनुसार सत्य हैं तथा विद्यालय के प्रवेश एवं निष्कासन पंजिका से लिए गए हैं।"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "sig_ct",
          "sigRole": "class_teacher",
          "name": "Sig · Class Teacher",
          "type": "text",
          "xMm": 15,
          "yMm": 252,
          "wMm": 52,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Class Teacher"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "कक्षा अध्यापक"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "sig_ck",
          "sigRole": "checked_by",
          "name": "Sig · Checked by",
          "type": "text",
          "xMm": 79,
          "yMm": 252,
          "wMm": 52,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "showWhen": "tc.duesPaidUpto",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Checked by"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "जाँचकर्ता"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "sig_pr",
          "sigRole": "principal",
          "name": "Sig · Principal",
          "type": "text",
          "xMm": 143,
          "yMm": 252,
          "wMm": 52,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Principal"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "प्रधानाचार्य"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "o_seal",
          "name": "School seal",
          "type": "shape",
          "xMm": 88,
          "yMm": 228,
          "wMm": 34,
          "hMm": 34,
          "z": 2,
          "height": "fixed",
          "content": {
            "shape": "seal"
          },
          "style": {}
        },
        {
          "id": "t_dup",
          "name": "Duplicate mark",
          "type": "text",
          "xMm": 130,
          "yMm": 38,
          "wMm": 65,
          "hMm": 8,
          "z": 8,
          "height": "auto",
          "showWhen": "doc.isDuplicate",
          "isDuplicateMark": true,
          "style": {
            "sizePt": 15,
            "lineHeight": 1.2,
            "weight": 700,
            "align": "right",
            "colour": "#C0392B",
            "track": ".22em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "DUPLICATE"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "द्वितीय प्रति"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "f_no",
          "name": "Page number",
          "region": "footer",
          "type": "pageNumber",
          "xMm": 15,
          "yMm": 4,
          "wMm": 180,
          "hMm": 5,
          "z": 1,
          "height": "fixed",
          "style": {
            "sizePt": 7.5,
            "lineHeight": 1.3,
            "align": "center",
            "colour": "#7A6A60"
          },
          "content": {
            "format": "Page {n} of {t}"
          }
        }
      ]
    }
  },
  {
    "id": "sec_ker",
    "docType": "school_education_certificate",
    "name": "Certificate of School Education",
    "meta": "Kerala · r.22A · field list Level A",
    "boards": null,
    "states": [
      "Kerala"
    ],
    "template": {
      "templateId": "TPL0021",
      "schoolId": "SCH_D94FE8F7AD",
      "docType": "school_education_certificate",
      "name": "Certificate of School Education — KER r.22A",
      "status": "draft",
      "version": 1,
      "publishedVersion": null,
      "activeVersion": null,
      "lockVersion": 1,
      "contractRef": "school_education_certificate_v1",
      "languages": [
        "en"
      ],
      "defaultLanguage": "en",
      "languageFallback": "block",
      "page": {
        "size": "A4",
        "orientation": "portrait",
        "marginsMm": {
          "t": 42,
          "r": 18,
          "b": 16,
          "l": 18
        },
        "pageMode": "single"
      },
      "objects": [
        {
          "id": "h_logo",
          "name": "School crest",
          "region": "header",
          "type": "image",
          "xMm": 15,
          "yMm": 8,
          "wMm": 20,
          "hMm": 20,
          "z": 1,
          "height": "fixed",
          "assetKind": "crest",
          "asset": null,
          "content": {
            "label": "School crest"
          },
          "style": {}
        },
        {
          "id": "h_name",
          "name": "School name",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 9,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "requiredKey": "school.name",
          "style": {
            "sizePt": 16,
            "lineHeight": 1.2,
            "weight": 700,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.name"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_addr",
          "name": "Address line",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 20,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.35,
            "weight": 400,
            "align": "left",
            "colour": "#4A3C33"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.address"
                  },
                  {
                    "t": "  ·  Affiliation No. "
                  },
                  {
                    "f": "school.affiliationNo"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_rule",
          "name": "Letterhead rule",
          "region": "header",
          "type": "shape",
          "xMm": 15,
          "yMm": 33,
          "wMm": 180,
          "hMm": 0.6,
          "z": 1,
          "height": "fixed",
          "content": {
            "shape": "line"
          },
          "style": {
            "colour": "#14100D"
          }
        },
        {
          "id": "t_title",
          "name": "Title",
          "type": "text",
          "xMm": 18,
          "yMm": 50,
          "wMm": 174,
          "hMm": 8,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 13,
            "lineHeight": 1.3,
            "weight": 700,
            "align": "center",
            "colour": "#14100D",
            "track": ".12em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "CERTIFICATE OF SCHOOL EDUCATION"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_body",
          "name": "Certifying paragraph",
          "type": "text",
          "xMm": 18,
          "yMm": 66,
          "wMm": 174,
          "hMm": 20,
          "z": 3,
          "height": "auto",
          "maxHMm": 44,
          "requiredKey": "student.fullName",
          "style": {
            "sizePt": 10.5,
            "lineHeight": 1.9,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "This is to certify that "
                  },
                  {
                    "f": "student.fullName"
                  },
                  {
                    "t": " son/daughter of "
                  },
                  {
                    "f": "student.fatherName"
                  },
                  {
                    "t": " was pupil of this school from "
                  },
                  {
                    "f": "sec.fromDate"
                  },
                  {
                    "t": " to "
                  },
                  {
                    "f": "sec.toDate"
                  },
                  {
                    "t": " and that he/she "
                  },
                  {
                    "f": "sec.outcome"
                  },
                  {
                    "t": " "
                  },
                  {
                    "f": "tc.lastClassStudied"
                  },
                  {
                    "t": " (in words)."
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_dob",
          "name": "Date of birth",
          "type": "text",
          "xMm": 18,
          "yMm": 96,
          "wMm": 174,
          "hMm": 8,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_body",
          "anchorGapMm": 6,
          "requiredKey": "student.dobWords",
          "style": {
            "sizePt": 10.5,
            "lineHeight": 1.9,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "His/Her date of birth is "
                  },
                  {
                    "f": "student.dobWords"
                  },
                  {
                    "t": " (in words) as per school records."
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_station",
          "name": "Station",
          "type": "text",
          "xMm": 18,
          "yMm": 150,
          "wMm": 70,
          "hMm": 7,
          "z": 3,
          "height": "auto",
          "requiredKey": "doc.station",
          "style": {
            "sizePt": 10,
            "lineHeight": 1.8,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Station: "
                  },
                  {
                    "f": "doc.station"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_date",
          "name": "Date",
          "type": "text",
          "xMm": 18,
          "yMm": 158,
          "wMm": 70,
          "hMm": 7,
          "z": 3,
          "height": "auto",
          "requiredKey": "doc.issueDate",
          "style": {
            "sizePt": 10,
            "lineHeight": 1.8,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Date: "
                  },
                  {
                    "f": "doc.issueDate"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "o_seal",
          "name": "School seal",
          "type": "shape",
          "xMm": 96,
          "yMm": 146,
          "wMm": 28,
          "hMm": 28,
          "z": 2,
          "height": "fixed",
          "content": {
            "shape": "seal"
          },
          "style": {}
        },
        {
          "id": "sig_hm",
          "sigRole": "headmaster",
          "name": "Sig · Headmaster",
          "type": "text",
          "xMm": 132,
          "yMm": 166,
          "wMm": 60,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "style": {
            "sizePt": 9.5,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Headmaster"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_note",
          "name": "Footnote",
          "type": "text",
          "xMm": 18,
          "yMm": 196,
          "wMm": 174,
          "hMm": 8,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 7.5,
            "lineHeight": 1.5,
            "weight": 400,
            "align": "left",
            "colour": "#6B5346"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "* Enter the name of the pupil in block capitals with full address. Issued to a pupil who left before appearing for the S.S.L.C. Examination, on application and on remittance of the prescribed fee. The daughters of widows are exempt from the fee where the certificate supports an application for marriage financial assistance, and the certificate must say so."
                  }
                ]
              }
            }
          }
        },
        {
          "id": "f_no",
          "name": "Page number",
          "region": "footer",
          "type": "pageNumber",
          "xMm": 15,
          "yMm": 4,
          "wMm": 180,
          "hMm": 5,
          "z": 1,
          "height": "fixed",
          "style": {
            "sizePt": 7.5,
            "lineHeight": 1.3,
            "align": "center",
            "colour": "#7A6A60"
          },
          "content": {
            "format": "Page {n} of {t}"
          }
        }
      ]
    }
  },
  {
    "id": "bonafide",
    "docType": "bonafide",
    "name": "Classic bonafide",
    "meta": "no statutory basis found · free format",
    "boards": null,
    "states": null,
    "template": {
      "templateId": "TPL0031",
      "schoolId": "SCH_D94FE8F7AD",
      "docType": "bonafide",
      "name": "Bonafide — classic",
      "status": "draft",
      "version": 1,
      "publishedVersion": null,
      "activeVersion": null,
      "lockVersion": 1,
      "contractRef": "bonafide_v1",
      "languages": [
        "en",
        "hi"
      ],
      "defaultLanguage": "en",
      "languageFallback": "block",
      "page": {
        "size": "A4",
        "orientation": "portrait",
        "marginsMm": {
          "t": 42,
          "r": 18,
          "b": 16,
          "l": 18
        },
        "pageMode": "single"
      },
      "objects": [
        {
          "id": "h_logo",
          "name": "School crest",
          "region": "header",
          "type": "image",
          "xMm": 15,
          "yMm": 8,
          "wMm": 20,
          "hMm": 20,
          "z": 1,
          "height": "fixed",
          "assetKind": "crest",
          "asset": null,
          "content": {
            "label": "School crest"
          },
          "style": {}
        },
        {
          "id": "h_name",
          "name": "School name",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 9,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "requiredKey": "school.name",
          "style": {
            "sizePt": 16,
            "lineHeight": 1.2,
            "weight": 700,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.name"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "f": "school.name"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_addr",
          "name": "Address line",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 20,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.35,
            "weight": 400,
            "align": "left",
            "colour": "#4A3C33"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.address"
                  },
                  {
                    "t": "  ·  Affiliation No. "
                  },
                  {
                    "f": "school.affiliationNo"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "f": "school.address"
                  },
                  {
                    "t": "  ·  संबद्धता क्रमांक "
                  },
                  {
                    "f": "school.affiliationNo"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_rule",
          "name": "Letterhead rule",
          "region": "header",
          "type": "shape",
          "xMm": 15,
          "yMm": 33,
          "wMm": 180,
          "hMm": 0.6,
          "z": 1,
          "height": "fixed",
          "content": {
            "shape": "line"
          },
          "style": {
            "colour": "#14100D"
          }
        },
        {
          "id": "t_title",
          "name": "Title",
          "type": "text",
          "xMm": 18,
          "yMm": 52,
          "wMm": 174,
          "hMm": 8,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 13,
            "lineHeight": 1.3,
            "weight": 700,
            "align": "center",
            "colour": "#14100D",
            "track": ".12em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "BONAFIDE CERTIFICATE"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "बोनाफाइड प्रमाणपत्र"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_no",
          "name": "Reference no.",
          "type": "text",
          "xMm": 18,
          "yMm": 66,
          "wMm": 174,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.5,
            "weight": 600,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Ref. No. "
                  },
                  {
                    "f": "doc.slNo"
                  },
                  {
                    "t": "          Date: "
                  },
                  {
                    "f": "doc.issueDate"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "संदर्भ सं. "
                  },
                  {
                    "f": "doc.slNo"
                  },
                  {
                    "t": "          दिनांक: "
                  },
                  {
                    "f": "doc.issueDate"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_body",
          "name": "Certifying paragraph",
          "type": "text",
          "xMm": 18,
          "yMm": 80,
          "wMm": 174,
          "hMm": 18,
          "z": 3,
          "height": "auto",
          "maxHMm": 40,
          "style": {
            "sizePt": 10.5,
            "lineHeight": 1.9,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "This is to certify that "
                  },
                  {
                    "f": "student.fullName"
                  },
                  {
                    "t": ", son/daughter of "
                  },
                  {
                    "f": "student.fatherName"
                  },
                  {
                    "t": ", bearing Admission No. "
                  },
                  {
                    "f": "student.admissionNumber"
                  },
                  {
                    "t": ", is a bonafide student of this school and is presently studying in Class "
                  },
                  {
                    "f": "tc.lastClassStudied"
                  },
                  {
                    "t": ". His/Her date of birth as recorded in the Admission Register is "
                  },
                  {
                    "f": "student.dob"
                  },
                  {
                    "t": "."
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "प्रमाणित किया जाता है कि "
                  },
                  {
                    "f": "student.fullName"
                  },
                  {
                    "t": ", पिता/माता "
                  },
                  {
                    "f": "student.fatherName"
                  },
                  {
                    "t": ", प्रवेश संख्या "
                  },
                  {
                    "f": "student.admissionNumber"
                  },
                  {
                    "t": ", इस विद्यालय के नियमित छात्र/छात्रा हैं तथा वर्तमान में कक्षा "
                  },
                  {
                    "f": "tc.lastClassStudied"
                  },
                  {
                    "t": " में अध्ययनरत हैं।"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_purpose",
          "name": "Purpose",
          "type": "text",
          "xMm": 18,
          "yMm": 104,
          "wMm": 174,
          "hMm": 8,
          "z": 3,
          "height": "auto",
          "anchorTo": "t_body",
          "anchorGapMm": 6,
          "style": {
            "sizePt": 10.5,
            "lineHeight": 1.9,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "This certificate is issued on request for official purposes."
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "यह प्रमाणपत्र अनुरोध पर शासकीय प्रयोजन हेतु जारी किया गया है।"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "o_seal",
          "name": "School seal",
          "type": "shape",
          "xMm": 24,
          "yMm": 150,
          "wMm": 28,
          "hMm": 28,
          "z": 2,
          "height": "fixed",
          "content": {
            "shape": "seal"
          },
          "style": {}
        },
        {
          "id": "sig_pr",
          "name": "Sig · Principal",
          "type": "text",
          "xMm": 132,
          "yMm": 168,
          "wMm": 60,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "style": {
            "sizePt": 9.5,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Principal"
                  }
                ]
              },
              "hi": {
                "runs": [
                  {
                    "t": "प्रधानाचार्य"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "f_no",
          "name": "Page number",
          "region": "footer",
          "type": "pageNumber",
          "xMm": 15,
          "yMm": 4,
          "wMm": 180,
          "hMm": 5,
          "z": 1,
          "height": "fixed",
          "style": {
            "sizePt": 7.5,
            "lineHeight": 1.3,
            "align": "center",
            "colour": "#7A6A60"
          },
          "content": {
            "format": "Page {n} of {t}"
          }
        }
      ]
    }
  },
  {
    "id": "conduct",
    "docType": "character",
    "name": "Conduct and character",
    "meta": "TNER r.34 / App. 5-B shape",
    "boards": null,
    "states": null,
    "template": {
      "templateId": "TPL0041",
      "schoolId": "SCH_D94FE8F7AD",
      "docType": "character",
      "name": "Conduct Certificate — TNER shape",
      "status": "draft",
      "version": 1,
      "publishedVersion": null,
      "activeVersion": null,
      "lockVersion": 1,
      "contractRef": "character_v1",
      "languages": [
        "en"
      ],
      "defaultLanguage": "en",
      "languageFallback": "block",
      "page": {
        "size": "A4",
        "orientation": "portrait",
        "marginsMm": {
          "t": 42,
          "r": 18,
          "b": 16,
          "l": 18
        },
        "pageMode": "single"
      },
      "objects": [
        {
          "id": "h_logo",
          "name": "School crest",
          "region": "header",
          "type": "image",
          "xMm": 15,
          "yMm": 8,
          "wMm": 20,
          "hMm": 20,
          "z": 1,
          "height": "fixed",
          "assetKind": "crest",
          "asset": null,
          "content": {
            "label": "School crest"
          },
          "style": {}
        },
        {
          "id": "h_name",
          "name": "School name",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 9,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "requiredKey": "school.name",
          "style": {
            "sizePt": 16,
            "lineHeight": 1.2,
            "weight": 700,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.name"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_addr",
          "name": "Address line",
          "region": "header",
          "type": "text",
          "xMm": 40,
          "yMm": 20,
          "wMm": 155,
          "hMm": 9,
          "z": 2,
          "height": "auto",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.35,
            "weight": 400,
            "align": "left",
            "colour": "#4A3C33"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.address"
                  },
                  {
                    "t": "  ·  Affiliation No. "
                  },
                  {
                    "f": "school.affiliationNo"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "h_rule",
          "name": "Letterhead rule",
          "region": "header",
          "type": "shape",
          "xMm": 15,
          "yMm": 33,
          "wMm": 180,
          "hMm": 0.6,
          "z": 1,
          "height": "fixed",
          "content": {
            "shape": "line"
          },
          "style": {
            "colour": "#14100D"
          }
        },
        {
          "id": "t_title",
          "name": "Title",
          "type": "text",
          "xMm": 18,
          "yMm": 52,
          "wMm": 174,
          "hMm": 8,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 13,
            "lineHeight": 1.3,
            "weight": 700,
            "align": "center",
            "colour": "#14100D",
            "track": ".12em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "CONDUCT AND CHARACTER CERTIFICATE"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "t_body",
          "name": "Certifying paragraph",
          "type": "text",
          "xMm": 18,
          "yMm": 70,
          "wMm": 174,
          "hMm": 18,
          "z": 3,
          "height": "auto",
          "maxHMm": 40,
          "style": {
            "sizePt": 10.5,
            "lineHeight": 1.9,
            "weight": 400,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "This is to certify that "
                  },
                  {
                    "f": "student.fullName"
                  },
                  {
                    "t": ", son/daughter of "
                  },
                  {
                    "f": "student.fatherName"
                  },
                  {
                    "t": ", studied in this school up to Class "
                  },
                  {
                    "f": "tc.lastClassStudied"
                  },
                  {
                    "t": " and left on "
                  },
                  {
                    "f": "tc.dateOfLeaving"
                  },
                  {
                    "t": ". During the period of study his/her conduct and character were "
                  },
                  {
                    "f": "tc.conductRemark"
                  },
                  {
                    "t": "."
                  }
                ]
              }
            }
          }
        },
        {
          "id": "o_seal",
          "name": "School seal",
          "type": "shape",
          "xMm": 24,
          "yMm": 140,
          "wMm": 28,
          "hMm": 28,
          "z": 2,
          "height": "fixed",
          "content": {
            "shape": "seal"
          },
          "style": {}
        },
        {
          "id": "sig_pr",
          "name": "Sig · Principal",
          "type": "text",
          "xMm": 132,
          "yMm": 158,
          "wMm": 60,
          "hMm": 8,
          "z": 3,
          "height": "fixed",
          "style": {
            "sizePt": 9.5,
            "lineHeight": 1.4,
            "weight": 600,
            "align": "center",
            "colour": "#14100D",
            "topRule": true
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Principal"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "f_no",
          "name": "Page number",
          "region": "footer",
          "type": "pageNumber",
          "xMm": 15,
          "yMm": 4,
          "wMm": 180,
          "hMm": 5,
          "z": 1,
          "height": "fixed",
          "style": {
            "sizePt": 7.5,
            "lineHeight": 1.3,
            "align": "center",
            "colour": "#7A6A60"
          },
          "content": {
            "format": "Page {n} of {t}"
          }
        }
      ]
    }
  },
  {
    "id": "fee_rct",
    "docType": "fee_receipt",
    "name": "Itemised fee receipt",
    "meta": "repeating line items · totals anchored below",
    "boards": null,
    "states": null,
    "template": {
      "templateId": "TPL_RCT",
      "name": "Fee receipt",
      "docType": "fee_receipt",
      "status": "draft",
      "version": 1,
      "publishedVersion": null,
      "activeVersion": null,
      "lockVersion": 0,
      "languages": [
        "en"
      ],
      "defaultLanguage": "en",
      "page": {
        "size": "A4",
        "orientation": "portrait",
        "marginsMm": {
          "t": 18,
          "r": 15,
          "b": 16,
          "l": 15
        },
        "pageMode": "single"
      },
      "header": [],
      "footer": [],
      "objects": [
        {
          "id": "r_logo",
          "name": "School crest",
          "region": "header",
          "type": "image",
          "xMm": 15,
          "yMm": 10,
          "wMm": 18,
          "hMm": 18,
          "z": 1,
          "height": "fixed",
          "content": {
            "label": "School crest"
          },
          "style": {}
        },
        {
          "id": "r_name",
          "name": "School name",
          "region": "header",
          "type": "text",
          "xMm": 38,
          "yMm": 11,
          "wMm": 157,
          "hMm": 8,
          "z": 2,
          "height": "auto",
          "requiredKey": "school.name",
          "style": {
            "sizePt": 13,
            "lineHeight": 1.25,
            "weight": 700,
            "align": "left",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.name"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "r_addr",
          "name": "Address line",
          "region": "header",
          "type": "text",
          "xMm": 38,
          "yMm": 20,
          "wMm": 157,
          "hMm": 6,
          "z": 2,
          "height": "auto",
          "requiredKey": "school.address",
          "style": {
            "sizePt": 8,
            "lineHeight": 1.4,
            "align": "left",
            "colour": "#6B5346"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "school.address"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "r_rule",
          "name": "Letterhead rule",
          "region": "header",
          "type": "shape",
          "xMm": 15,
          "yMm": 31,
          "wMm": 180,
          "hMm": 0.6,
          "z": 1,
          "height": "fixed",
          "content": {
            "shape": "line"
          },
          "style": {
            "colour": "#BC5A3C"
          }
        },
        {
          "id": "r_dup",
          "name": "Duplicate mark",
          "type": "text",
          "xMm": 120,
          "yMm": 36,
          "wMm": 75,
          "hMm": 6,
          "z": 4,
          "height": "auto",
          "showWhen": "doc.isDuplicate",
          "isDuplicateMark": true,
          "style": {
            "sizePt": 11,
            "lineHeight": 1.2,
            "weight": 700,
            "align": "right",
            "colour": "#BC5A3C",
            "track": ".18em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "DUPLICATE"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "r_title",
          "name": "Title",
          "type": "text",
          "xMm": 15,
          "yMm": 38,
          "wMm": 180,
          "hMm": 8,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 12,
            "lineHeight": 1.3,
            "weight": 700,
            "align": "center",
            "colour": "#14100D",
            "track": ".08em"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "FEE RECEIPT"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "r_meta",
          "name": "Receipt number and date",
          "type": "text",
          "xMm": 15,
          "yMm": 50,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.5,
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Receipt No.  "
                  },
                  {
                    "f": "receipt.no"
                  },
                  {
                    "t": "          Date: "
                  },
                  {
                    "f": "receipt.date"
                  },
                  {
                    "t": "          Session: "
                  },
                  {
                    "f": "receipt.session"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "r_who",
          "name": "Received from",
          "type": "text",
          "xMm": 15,
          "yMm": 58,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.5,
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Received with thanks from "
                  },
                  {
                    "f": "student.fullName"
                  },
                  {
                    "t": "  (Adm. No. "
                  },
                  {
                    "f": "student.admissionNumber"
                  },
                  {
                    "t": ", Class "
                  },
                  {
                    "f": "tc.lastClassStudied"
                  },
                  {
                    "t": ")"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "r_items",
          "name": "Fee items",
          "type": "table",
          "xMm": 15,
          "yMm": 68,
          "wMm": 180,
          "hMm": 40,
          "z": 3,
          "height": "auto",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.5,
            "colour": "#14100D"
          },
          "content": {
            "repeatOver": "receipt.items",
            "showHeader": true,
            "columns": [
              {
                "key": "item.head",
                "wPct": 52
              },
              {
                "key": "item.period",
                "wPct": 26
              },
              {
                "key": "item.amount",
                "wPct": 22,
                "align": "right"
              }
            ]
          }
        },
        {
          "id": "r_net",
          "name": "Net amount paid",
          "type": "text",
          "xMm": 15,
          "yMm": 0,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "anchorTo": "r_items",
          "anchorGapMm": 4,
          "requiredKey": "receipt.netPaid",
          "style": {
            "sizePt": 10,
            "lineHeight": 1.4,
            "weight": 700,
            "align": "right",
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Net amount paid:  "
                  },
                  {
                    "f": "receipt.netPaid"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "r_words",
          "name": "Amount in words",
          "type": "text",
          "xMm": 15,
          "yMm": 0,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "anchorTo": "r_net",
          "anchorGapMm": 2,
          "requiredKey": "receipt.amountInWords",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.5,
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "f": "receipt.amountInWords"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "r_mode",
          "name": "Payment mode",
          "type": "text",
          "xMm": 15,
          "yMm": 0,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "anchorTo": "r_words",
          "anchorGapMm": 4,
          "requiredKey": "receipt.mode",
          "style": {
            "sizePt": 9,
            "lineHeight": 1.5,
            "colour": "#14100D"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "Paid by "
                  },
                  {
                    "f": "receipt.mode"
                  },
                  {
                    "t": "          Collected by: "
                  },
                  {
                    "f": "receipt.collectedBy"
                  }
                ]
              }
            }
          }
        },
        {
          "id": "r_note",
          "name": "Computer generated note",
          "type": "text",
          "xMm": 15,
          "yMm": 0,
          "wMm": 180,
          "hMm": 6,
          "z": 3,
          "height": "auto",
          "anchorTo": "r_mode",
          "anchorGapMm": 8,
          "style": {
            "sizePt": 7.5,
            "lineHeight": 1.4,
            "align": "center",
            "colour": "#9E8578"
          },
          "content": {
            "i18n": {
              "en": {
                "runs": [
                  {
                    "t": "This is a computer-generated receipt. Please retain it for your records."
                  }
                ]
              }
            }
          }
        },
        {
          "id": "r_page",
          "name": "Page number",
          "region": "footer",
          "type": "pageNumber",
          "xMm": 15,
          "yMm": 4,
          "wMm": 180,
          "hMm": 5,
          "z": 1,
          "height": "fixed",
          "content": {
            "format": "Page {n} of {t}"
          },
          "style": {
            "sizePt": 7.5,
            "lineHeight": 1.3,
            "align": "center",
            "colour": "#9E8578"
          }
        }
      ]
    }
  }
]
JSON
, true);
