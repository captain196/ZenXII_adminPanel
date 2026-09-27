#!/bin/sh
unset JAVA_TOOL_OPTIONS
echo "CAL_D1_RULES inside jest shell = $CAL_D1_RULES"
exec npx jest cal_d1_tenant_binding --runInBand --forceExit -t "cannot CREATE a calendarEvents"
