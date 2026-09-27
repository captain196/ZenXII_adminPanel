#!/bin/sh
unset JAVA_TOOL_OPTIONS
export NODE_OPTIONS="--max-old-space-size=320"
exec npx jest timetable_sec --runInBand --forceExit
