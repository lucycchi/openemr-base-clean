/**
 * The form for adding a prescription, or changing one (ARC-06). In: starting values (empty for Add, the
 * row's values for Change), the signed-in prescriber's name, today's date, a clock for "date added",
 * what to do on Save and on Cancel. Out: the form on screen. Save sends the typed values to `onSave`,
 * which answers with an outcome: field messages are shown beside their fields, a failure is shown as an
 * alert, and everything typed is kept so nothing has to be re-entered.
 *
 * The fields follow the old prescription form's order (templates/prescription/general_edit.html.twig):
 * drug, directions, quantity, refills (0 to 20, as the old form's list), start date. The prescriber is
 * free text saved in the prescription's note, because OpenEMR's own prescriber field cannot be filled
 * through its API (ARC-06 decision 2); the note under the box says so.
 */
import { useEffect, useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { PrescriptionForm, WriteOutcome } from '../api/prescriptionWrites';

/** The old form offers 0 to 20 refills (Prescription.class.php refills_array). */
const REFILL_CHOICES = Array.from({ length: 21 }, (_, n) => n);

export function PrescriptionFormPanel({
    initial,
    prescriberDefault,
    today,
    nowText,
    onSave,
    onCancel,
}: {
    /** Starting values; anything missing starts empty (or today, 0 refills, the signed-in prescriber). */
    initial: Partial<PrescriptionForm>;
    prescriberDefault: string;
    /** Today as YYYY-MM-DD, the start date's default. */
    today: string;
    /** The local date and time as "YYYY-MM-DD HH:MM:SS", read when Save is pressed. */
    nowText: () => string;
    onSave: (form: PrescriptionForm) => Promise<WriteOutcome>;
    onCancel: () => void;
}) {
    const [drug, setDrug] = useState(initial.drug ?? '');
    const [dosage, setDosage] = useState(initial.dosage ?? '');
    const [quantity, setQuantity] = useState(initial.quantity ?? '');
    const [refills, setRefills] = useState(initial.refills ?? 0);
    const [startDate, setStartDate] = useState(initial.startDate ?? today);
    const [prescriber, setPrescriber] = useState(initial.prescriber ?? prescriberDefault);
    // The server's message per field, and a message for the whole form, from the last Save.
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [alert, setAlert] = useState('');
    // True while a Save is on its way; the ref blocks a second Save even before the page redraws.
    const [saving, setSaving] = useState(false);
    const savingRef = useRef(false);
    // False once the form has closed, so a late answer does not update a form that is gone.
    const mounted = useRef(true);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const id = useId();

    async function save(event: FormEvent) {
        event.preventDefault();
        if (savingRef.current) {
            return;
        }
        savingRef.current = true;
        setSaving(true);
        setErrors({});
        setAlert('');
        const outcome = await onSave({
            drug,
            dosage,
            quantity,
            refills,
            startDate,
            dateAdded: nowText(),
            prescriber,
        });
        savingRef.current = false;
        if (!mounted.current) {
            return;
        }
        setSaving(false);
        if (outcome.kind === 'invalid') {
            setErrors(outcome.errors);
            // Messages that are not about one field (the page clock, the form as a whole) go above the buttons.
            setAlert(outcome.errors.dateAdded ?? outcome.errors.form ?? '');
        } else if (outcome.kind === 'failed' || outcome.kind === 'partly-saved') {
            setAlert(outcome.message);
        }
        // 'saved': the card closes the form and reloads the list.
    }

    /** A labelled text box with its server message underneath, when there is one. */
    function field(
        name: keyof PrescriptionForm,
        label: string,
        value: string,
        onChange: (v: string) => void,
        type = 'text',
    ) {
        const fieldId = `${id}-${name}`;
        return (
            <div className="form-group mb-2">
                <label htmlFor={fieldId} className="mb-0 small">
                    {label}
                </label>
                <input
                    id={fieldId}
                    type={type}
                    className={
                        errors[name] === undefined
                            ? 'form-control form-control-sm'
                            : 'form-control form-control-sm is-invalid'
                    }
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                />
                {errors[name] !== undefined && <div className="invalid-feedback d-block">{errors[name]}</div>}
            </div>
        );
    }

    return (
        <form className="border rounded p-2 mb-2" onSubmit={(event) => void save(event)}>
            {field('drug', 'Drug', drug, setDrug)}
            {field('dosage', 'Directions', dosage, setDosage)}
            {field('quantity', 'Quantity', quantity, setQuantity)}
            <div className="form-group mb-2">
                <label htmlFor={`${id}-refills`} className="mb-0 small">
                    Refills
                </label>
                <select
                    id={`${id}-refills`}
                    className="form-control form-control-sm"
                    value={refills}
                    onChange={(event) => setRefills(Number(event.target.value))}
                >
                    {REFILL_CHOICES.map((n) => (
                        <option key={n} value={n}>
                            {n}
                        </option>
                    ))}
                </select>
                {errors.refills !== undefined && <div className="invalid-feedback d-block">{errors.refills}</div>}
            </div>
            {field('startDate', 'Start date', startDate, setStartDate, 'date')}
            {field('prescriber', 'Prescriber', prescriber, setPrescriber)}
            <p className="small text-muted mt-n1">
                Saved in the prescription's note. OpenEMR's own prescriber field stays empty, because its API cannot set
                it.
            </p>
            {alert !== '' && (
                <p role="alert" className="text-danger small">
                    {alert}
                </p>
            )}
            <button type="submit" className="btn btn-primary btn-sm mr-2" disabled={saving}>
                Save
            </button>
            <button type="button" className="btn btn-link btn-sm" onClick={onCancel} disabled={saving}>
                Cancel
            </button>
        </form>
    );
}
