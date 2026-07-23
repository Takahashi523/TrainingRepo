import { cn } from "@/lib/utils";

export const STATUS_STYLES: Record<
    string,
    { badgeClass: string; accentClass: string }
> = {
    // engineers.status
    proposable: {
        badgeClass: "border-green-600 text-green-700 bg-green-50",
        accentClass: "bg-green-600",
    },
    interviewing: {
        badgeClass: "border-amber-500 text-amber-700 bg-amber-50",
        accentClass: "bg-amber-500",
    },
    not_proposable: {
        badgeClass: "border-gray-400 text-gray-600 bg-gray-50",
        accentClass: "bg-gray-400",
    },

    // projects.status
    open: {
        badgeClass: "border-green-600 text-green-700 bg-green-50",
        accentClass: "bg-green-600",
    },
    pending: {
        badgeClass: "border-amber-500 text-amber-700 bg-amber-50",
        accentClass: "bg-amber-500",
    },
    closed: {
        badgeClass: "border-gray-400 text-gray-600 bg-gray-50",
        accentClass: "bg-gray-400",
    },
};

const DEFAULT_STYLE = {
    badgeClass: "border-gray-400 text-gray-600 bg-gray-50",
    accentClass: "bg-gray-400",
};

interface Props {
    status: string;
    label: string;
}

export default function StatusBadge({ status, label }: Props) {
    const s = STATUS_STYLES[status] ?? DEFAULT_STYLE;
    return (
        <span
            className={cn(
                "rounded-full border px-3 py-0.5 text-xs font-bold",
                s.badgeClass,
            )}
        >
            {label}
        </span>
    );
}
