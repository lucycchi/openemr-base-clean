# Patient dashboard inventory

A factual map of what the current patient dashboard loads, without critique; problems go in `BUGS-MITIGATIONS.md`. Line numbers are from the `dashboard-migration` branch at the time of writing (2026-09-26). The tree was built from every include, render, fragment, event and ACL call site in `demographics.php`, `stats.php` and the fragments it loads. Each in-scope region is read in full during its module audit.

## Tree

```
interface/main/tabs/main.php                         (tab frame around every page)
├── templates/patient_data_template.php               identity bar markup (Knockout bindings; MRN ~line 102, DOB ~line 112)
└── js/patient_data_view_model.js                     patient_data_view_model(pname, pid, pubpid, str_dob)
    └── js/frame_proxies.js:37                        setPatient() creates/updates the view model
        └── filled from demographics.php:924          parent.left_nav.setPatient(name, pid, pubpid, '', "DOB: … Age: …" or "… Age at death: …")

interface/patient_file/summary/demographics.php      dashboard entry point (≈2,080 lines)
├── includes
│   ├── ../../globals.php (30)
│   ├── library/lists.inc.php (37), patient.inc.php (38), options.inc.php (39), clinical_rules.php (41), group.inc.php (42), appointments.inc.php (43)
│   ├── interface/patient_file/history/history.inc.php (40)
│   ├── library/pid.inc.php (85, only when set_pid is given)
│   ├── library/options.js.php (385)
│   └── interface/patient_file/summary/dashboard_header.php (1076)
│       └── templates/patient/dashboard_header.html.twig ← OemrUI::pageHeading() (src/OeUI/OemrUI.php:150)
├── page set-up
│   ├── getHiddenDashboardCards() (138), reads globals.gl_name = 'hide_dashboard_cards' straight from SQL; result in $hiddenCards (101)
│   ├── ViewEvent dispatch (1055); aclCheckCore('patients','demo') (1056), which renders core/unauthorized-partial.html.twig when denied (1059)
│   ├── squad check aclCheckCore('squads', …) (1069)
│   └── RenderEvent EVENT_SECTION_LIST_RENDER_* dispatches (1075, 1354, 1533) and EVENT_RENDER_POST_PAGELOAD (2079)
├── card visibility flags (1095–1098)
│   ├── $allergy = aclCheckIssue('allergy') && !card_allergies
│   ├── $pl      = aclCheckIssue('medical_problem') && !card_medicalproblems
│   ├── $meds    = aclCheckIssue('medication') && !card_medication
│   └── $rx      = !disable_prescriptions && aclCheckCore('patients','rx') && !card_prescriptions
├── first column, server-rendered cards
│   ├── ALLERGY CARD (1115–1134)            → templates/patient/card/allergies.html.twig (AllergyIntoleranceService)
│   ├── MEDICAL PROBLEMS CARD (1140–1158)   → templates/patient/card/medical_problems.html.twig (PatientIssuesService)
│   ├── MEDICATION CARD (1162–1180)         → templates/patient/card/medication.html.twig
│   ├── Prescriptions (1184–1247)
│   │   ├── erx_enable && display_current_medications_below == 1 → templates/patient/card/erx.html.twig (own SQL on prescriptions, 1187)
│   │   └── always → templates/patient/card/rx.html.twig, whose body is a Smarty fragment:
│   │       Controller::dispatch(prescription, fragment) (1240) → controllers/C_Prescription.class.php → templates/prescription/general_fragment.html
│   ├── Care team (1252–1273) → src/Patient/Cards/CareTeamViewCard.php → templates/patient/card/manage_care_team.html.twig
│   ├── TREATMENT INTERVENTION PREFERENCES (1278–1300) → TreatmentPreferenceViewCard → templates/patient/card/preference_card_inline.html.twig
│   ├── CARE EXPERIENCE PREFERENCES (1304–1328) → CareExperiencePreferenceViewCard → templates/patient/card/preference_card_inline.html.twig
│   ├── deceased banner → templates/patient/partials/deceased.html.twig (1334)
│   └── SectionEvent('primary') (1339)
│       ├── DemographicsViewCard (1340) → templates/patient/card/tab_base.html.twig
│       ├── BillingViewCard (1343, unless hide_billing_widget) → templates/patient/card/billing.html.twig
│       └── InsuranceViewCard (1347, unless card_insurance) → templates/patient/card/insurance.html.twig
├── cards filled in after page load by placeHtml() (JS, 528–715)
│   ├── stats.php → #stats_div (606)
│   │   ├── templates/patient/card/erx.html.twig (180)
│   │   ├── templates/patient/card/medication.html.twig (231), templates/patient/card/medical_problems.html.twig (233, 342)
│   │   ├── templates/patient/card/tp_il.html.twig (261)
│   │   └── templates/patient/card/immunizations.html.twig (304)
│   ├── pnotes_fragment.php → #pnotes_ps_expand (611, 622)   notes card shell: loader.html.twig (1400)
│   ├── disc_fragment.php → #disclosures_ps_expand (626)     shell: loader.html.twig (1443)
│   ├── labdata_fragment.php → #labdata_ps_expand (627)      shell: loader.html.twig (1503)
│   ├── track_anything_fragment.php → #track_anything_ps_expand (628)   shell: loader.html.twig (2035)
│   ├── vitals_fragment.php → #vitals_ps_expand (631, if the vitals form is registered and aclCheckCore('patients','med'))   shell: loader.html.twig (1528)
│   ├── clinical_reminders_fragment.php → #clinical_reminders_ps_expand (635)   shell: loader.html.twig (1744)
│   └── patient_reminders_fragment.php → #patient_reminders_ps_expand (715)     shell: loader.html.twig (1420)
├── second column
│   ├── notes (1384–1400), aclCheckCore('patients','notes'); CardRenderEvent('note')
│   ├── patient reminders (1403–1420), enable_cdr + aclCheckCore('patients','reminder'); card_patientreminders
│   ├── disclosures (1424–1443), aclCheckCore('patients','disclosure'); card_disclosure
│   ├── amendments (1447–1473), amendments global + aclCheckCore('patients','amendment') → templates/patient/card/amendments.html.twig
│   ├── labs (1477–1503), aclCheckCore('patients','lab'); card_lab
│   ├── vitals (1507–1528), aclCheckCore('patients','med'); card_vitals
│   ├── LBF chartable forms (1535–1575), one loader card per repeating LBF group, with per-form ACO
│   ├── SectionEvent('secondary') (1595): PortalCard (1590) → templates/patient/partials/portal.html.twig
│   ├── eRx notice → templates/patient/partials/erx.html.twig (1633)
│   ├── ID card and photos → templates/patient/card/photo.html.twig (1659)
│   ├── advance directives → templates/patient/card/adv_dir.html.twig (1721)
│   ├── clinical reminders (1729–1744), enable_cdr_crw + aclCheckCore('patients','alert')
│   ├── appointments (1751–2020), !disable_calendar + aclCheckCore('patients','appt')
│   │   ├── recall → templates/patient/card/recall.html.twig (1913)
│   │   └── current, upcoming and past appointments → templates/patient/card/appointments.html.twig (2002)
│   ├── track anything (2026–2035)
│   └── delete patient → templates/patient/partials/delete.html.twig (2047, allow_pat_delete + aclCheckCore('admin','super'))
└── globals read in this file (count): enable_cdr (5), erx_enable (4), disable_calendar (4), time_display_format (4),
    patient_id_category_name (3), num_past_appointments_to_show (3), enable_cdr_prw, enable_cdr_crw, portal_onsite_two_enable,
    patient_photo_category_name, enable_allergy_check (2 each), disable_prescriptions, hide_billing_widget, amendments,
    allow_pat_delete, insurance_only_one, patient_birthday_alert, advance_directives_warning, appt_* display sets,
    rest_api, rest_fhir_api, rest_portal_api (1 each)

interface/patient_file/history/encounters.php          encounter history; not on the dashboard, reached from the patient menu
```

## Scope

| Module | Depth | Entry lines | Templates | Fragments | Toggles |
|---|---|---|---|---|---|
| Header | full audit | demographics.php:924 (identity bar), 1076 (page heading) | interface/main/tabs/templates/patient_data_template.php, templates/patient/dashboard_header.html.twig | none | aclCheckCore patients demo (1056); squad ACL (1069) |
| Allergies | full audit | demographics.php:1115-1134 | templates/patient/card/allergies.html.twig | none | aclCheckIssue allergy; card_allergies |
| Problem List | full audit | demographics.php:1140-1158 | templates/patient/card/medical_problems.html.twig | stats.php (also renders it) | aclCheckIssue medical_problem; card_medicalproblems |
| Medications | full audit | demographics.php:1162-1180 | templates/patient/card/medication.html.twig | stats.php (also renders it) | aclCheckIssue medication; card_medication |
| Prescriptions | full audit | demographics.php:1184-1247 | templates/patient/card/rx.html.twig, templates/patient/card/erx.html.twig, templates/prescription/general_fragment.html | controllers/C_Prescription.class.php fragment | disable_prescriptions; aclCheckCore patients rx; card_prescriptions; erx_enable |
| Care Team | full audit | demographics.php:1252-1273 | templates/patient/card/manage_care_team.html.twig | none | card_care_team; card ACL via CareTeamViewCard |
| Vitals | light review | demographics.php:1507-1528, 631 | templates/patient/card/loader.html.twig | vitals_fragment.php | aclCheckCore patients med; card_vitals |
| Labs | light review | demographics.php:1477-1503, 627 | templates/patient/card/loader.html.twig | labdata_fragment.php | aclCheckCore patients lab; card_lab |
| Notes | light review | demographics.php:1384-1400, 611 | templates/patient/card/loader.html.twig | pnotes_fragment.php | aclCheckCore patients notes |
| Immunizations | light review | stats.php:272-304 | templates/patient/card/immunizations.html.twig | stats.php | disable_immunizations; weight_loss_clinic; no ACL check (possible missing permission check) |
| Appointments | light review | demographics.php:1751-2020 | templates/patient/card/appointments.html.twig, templates/patient/card/recall.html.twig | none | disable_calendar; aclCheckCore patients appt |
| Encounters | light review | not on the dashboard; interface/patient_file/history/encounters.php | none on the dashboard | none | aclCheckCore encounters notes, notes_a, coding_a (encounters.php:67-69) |
| Treatment preferences | out of scope | demographics.php:1278-1300 | templates/patient/card/preference_card_inline.html.twig | none | card_treatment_preferences |
| Care experience preferences | out of scope | demographics.php:1304-1328 | templates/patient/card/preference_card_inline.html.twig | none | card_care_experience |
| Demographics card | out of scope | demographics.php:1340 | templates/patient/card/tab_base.html.twig | none | aclCheckCore patients demo |
| Billing | out of scope | demographics.php:1343 | templates/patient/card/billing.html.twig | none | hide_billing_widget |
| Insurance | out of scope | demographics.php:1347 | templates/patient/card/insurance.html.twig | none | card_insurance |
| Deceased banner | out of scope | demographics.php:1334 | templates/patient/partials/deceased.html.twig | none | none |
| Patient reminders | out of scope | demographics.php:1403-1420, 715 | templates/patient/card/loader.html.twig | patient_reminders_fragment.php | enable_cdr; card_patientreminders |
| Clinical reminders | out of scope | demographics.php:1729-1744, 635 | templates/patient/card/loader.html.twig | clinical_reminders_fragment.php | enable_cdr_crw; aclCheckCore patients alert |
| Disclosures | out of scope | demographics.php:1424-1443, 626 | templates/patient/card/loader.html.twig | disc_fragment.php | card_disclosure |
| Amendments | out of scope | demographics.php:1447-1473 | templates/patient/card/amendments.html.twig | none | amendments; card_amendments |
| Treatment plan / issue list | out of scope | stats.php:261 | templates/patient/card/tp_il.html.twig | stats.php | none |
| LBF chartable forms | out of scope | demographics.php:1535-1575 | templates/patient/card/loader.html.twig | none | per-form ACO |
| Portal | out of scope | demographics.php:1590-1595 | templates/patient/partials/portal.html.twig | none | portal_onsite_two_enable |
| eRx notice | out of scope | demographics.php:1633 | templates/patient/partials/erx.html.twig | none | erx_enable |
| Photos and ID card | out of scope | demographics.php:1639-1659 | templates/patient/card/photo.html.twig | none | patient_photo_category_name |
| Advance directives | out of scope | demographics.php:1662-1721 | templates/patient/card/adv_dir.html.twig | none | advance_directives_warning |
| Track anything | out of scope | demographics.php:2026-2035, 628 | templates/patient/card/loader.html.twig | track_anything_fragment.php | form registered |
| Delete patient | out of scope | demographics.php:2047 | templates/patient/partials/delete.html.twig | none | allow_pat_delete; aclCheckCore admin super |

## Out of scope, and why

- **Treatment and care experience preferences:** not among the challenge's required cards or extra-section options.
- **Demographics, billing and insurance cards:** administrative and financial; the challenge's header covers identity.
- **Deceased banner:** folded into the header's status field instead of ported as a separate card (decided at Gate 2).
- **Patient and clinical reminders:** decision-support widgets, not listed by the challenge.
- **Disclosures and amendments:** record-keeping workflows, not listed.
- **Treatment plan / issue list:** not listed.
- **LBF chartable forms:** site-configured forms with no stable FHIR mapping.
- **Portal, eRx notice, photos and ID card, advance directives, track anything:** not listed, and mostly write or launch workflows.
- **Delete patient:** a destructive admin action; a read-only port leaves it out.
